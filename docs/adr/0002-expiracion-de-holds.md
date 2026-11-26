# ADR 0002 — Expiración de holds: limpieza oportunista + job de higiene

- **Estado:** Aceptado
- **Fecha:** 2026-11-26
- **Contexto:** implementación de Fase 1/2. Corrige una contradicción del ADR 0001 y de la spec de concurrencia (C5).

## Problema

La restricción de exclusión solo mira el estado de la fila:

```sql
EXCLUDE USING gist (resource_id WITH =, time_range WITH &&)
WHERE (status IN ('held', 'confirmed'));
```

Un hold vencido (`expires_at <= now()`) **sigue bloqueando el slot**: el índice no
evalúa el tiempo. PostgreSQL no permite `now()` en el predicado de un índice
parcial porque no es inmutable, así que no es posible escribir
`WHERE status = 'confirmed' OR expires_at > now()`.

El ADR 0001 decía "expiración lazy, sin job"; la spec C5 decía "job cada 60 s".
Las dos no pueden ser ciertas a la vez. Con el job solamente, la ventana de
inconsistencia llega a 60 s y la Fase 3 (k6) iría **antes** de la Fase 4
(limpieza), dejando holds abandonados que envenenan los slots durante las
pruebas de carga.

## Alternativas consideradas

### A. Solo job periódico (lo que decía C5)

`UPDATE ... SET status = 'expired' WHERE status = 'held' AND expires_at <= now()`
cada 60 s.

- Simple, pero deja una ventana de hasta 60 s con el slot bloqueado.
- La corrección depende de que el scheduler esté vivo.
- Ordena mal las fases: la evidencia (k6) queda después de la limpieza.

### B. Codificar el vencimiento en el rango (descartada)

`tstzrange(start_at, LEAST(end_at, expires_at), '[)')`.

Parece elegante y **fue probada**: falla en el caso normal de reservar a futuro.
Si el slot es mañana a las 10:00 y el TTL es de 10 minutos, entonces
`expires_at < start_at` y el rango queda vacío. Un rango vacío no solapa con
nada (`&&` es falso), así que el hold **no bloquea el slot**. Dos usuarios
pueden tomar el mismo horario futuro simultáneamente.

La conclusión es conceptual: el TTL no pertenece a la ventana del slot. "Activo
ahora" no se puede expresar en una columna generada inmutable.

### C. Tabla `occupancy` separada (correcta, más superficie)

Una fila por intervalo ocupado: `hold` (con TTL) o `booking`. El `EXCLUDE` va
sobre esa tabla; confirmar = `INSERT booking` + `DELETE hold`; expirar = `DELETE`.
Elimina la ventana de lectura, pero agrega una tabla, una migración de datos y
una transición más. Queda como opción si el dominio crece.

### D. Limpieza oportunista en la ruta de escritura + job de higiene (elegida)

Antes de insertar una reserva, en la **misma transacción**:

```sql
UPDATE reservations
   SET status = 'expired', updated_at = now()
 WHERE id IN (
     SELECT id
       FROM reservations
      WHERE resource_id = :resource
        AND status = 'held'
        AND expires_at <= now()
        AND time_range && tstzrange(:start, :end, '[)')
      ORDER BY id
        FOR UPDATE
 )
```

El `ORDER BY id` da un orden de lock determinista entre transacciones
concurrentes. El `INSERT` siguiente ya no encuentra el hold en el índice.

El job cada minuto (`slotlock:expire-holds`) queda como higiene y para métricas,
no como garantía de corrección. Se agrega `idx_reservations_held_expiry` sobre
`(expires_at) WHERE status = 'held'`, porque el índice anterior
`(resource_id, expires_at)` no sirve para un barrido global.

## Consecuencias

**Positivas**

- La ventana de "slot muerto" para escrituras es cero: el primer intento de
  reservar expira el hold vencido y toma el slot.
- No se necesita `now()` en índices ni un nivel de aislamiento más alto.
- Confirmar y limpiar compiten por el row lock; el perdedor ve 0 filas y
  responde 410, que es el resultado correcto.
- Las lecturas no cambian: ya filtran por estado y `expires_at`.

**Negativas**

- Un `UPDATE` extra en la ruta de reserva. La contención está acotada por
  recurso + rango, y el caso normal no encuentra filas.
- Si nadie intenta reservar, la fila sigue en `held` hasta que corre el job;
  es solo un tema de higiene, no de corrección.
- La tabla crece si el scheduler está caído (el job es barato; el riesgo es
  operativo).

## Evidencia

- `tests/Feature/ReservationFlowTest.php` → `test_expired_hold_is_expired_opportunistically`:
  hold vencido + nueva reserva del mismo slot → 201, fila vieja en `expired`,
  dos filas en total.
- `tests/Feature/ReservationFlowTest.php` → `test_postgres_rejects_overlapping_reservations`:
  el SQLSTATE `23P01` se verifica sin pasar por el servicio.
- La trampa del rango vacío queda documentada para que no se reintente.
