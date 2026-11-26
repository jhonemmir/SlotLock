# EXPLAIN — disponibilidad y barrido de holds

- **Fecha:** 2026-11-26
- **PostgreSQL:** 16-alpine (Docker)
- **Datos:** 30.000 reservas `confirmed` consecutivas de 15 min sobre un mismo recurso, `ANALYZE` ejecutado.
- **Método:** todo dentro de una transacción con `ROLLBACK` (los datos no quedan en la DB).
- **Consulta:** ventana de 3 horas (12 slots de 15 min) sobre un recurso.

## Q1 — Disponibilidad por rango (`time_range && ...`)

```sql
SELECT start_at, end_at, status
  FROM reservations
 WHERE resource_id = :resource
   AND time_range && tstzrange(:from, :to, '[)')
   AND (status = 'confirmed' OR (status = 'held' AND expires_at > now()))
 ORDER BY start_at;
```

```text
Sort  (actual time=0.145..0.149 rows=12 loops=1)
  Buffers: shared hit=4
  ->  Bitmap Heap Scan on reservations  (actual time=0.111..0.120 rows=12 loops=1)
        Recheck Cond: ((resource_id = ...) AND (time_range && ...) AND (status = 'held' OR 'confirmed'))
        Heap Blocks: exact=1
        ->  Bitmap Index Scan on reservations_no_overlap  (actual time=0.085..0.086 rows=12)
              Index Cond: ((resource_id = ...) AND (time_range && ...))
Execution Time: 0.319 ms
```

**Lectura:** el índice GiST que crea la restricción `EXCLUDE` **también sirve la consulta de disponibilidad**. No hace falta un índice de rangos adicional: 12 filas, 4 buffers, 0.3 ms.

## Q2 — La misma ventana usando el btree

```sql
... WHERE resource_id = :resource
      AND start_at >= :from AND start_at < :to
      AND (status = 'confirmed' OR (status = 'held' AND expires_at > now()))
  ORDER BY start_at;
```

```text
Index Scan using reservations_resource_id_start_at_index  (actual time=0.033..0.039 rows=12)
  Index Cond: ((resource_id = ...) AND (start_at >= ...) AND (start_at < ...))
  Filter: ((status = 'confirmed') OR ((status = 'held') AND (expires_at > now())))
  Buffers: shared hit=3
Execution Time: 0.076 ms
```

**Lectura:** el btree es ~4× más rápido para esta forma exacta y además cubre `ORDER BY start_at`. Se mantienen los dos índices: el GiST es obligatorio para la invariante y cubre consultas por rango arbitrarias; el btree es barato y acelera la grilla.

## Q3 — Barrido global del job de holds vencidos

```sql
UPDATE reservations
   SET status = 'expired'
 WHERE id IN (
     SELECT id FROM reservations
      WHERE status = 'held' AND expires_at <= now()
      ORDER BY id FOR UPDATE
 )
```

```text
Update on reservations  (actual time=0.063..0.065 rows=0)
  ->  Nested Loop
        ->  HashAggregate
              ->  Subquery Scan on "ANY_subquery"
                    ->  LockRows
                          ->  Sort  (Sort Key: reservations_1.id)
                                ->  Index Scan using idx_reservations_held_expiry on reservations
                                      Index Cond: (expires_at <= now())
                                      Filter: ((status)::text = 'held'::text)
Execution Time: 0.300 ms
```

**Lectura:** el índice parcial `(expires_at) WHERE status='held'` es el que usa el job. El índice anterior `(resource_id, expires_at)` no servía para un barrido global porque su primera columna es el recurso.

## Reproducir

```sql
BEGIN;

INSERT INTO resources (id, name, type, capacity, timezone, max_duration_minutes, created_at, updated_at)
VALUES ('99999999-9999-4999-8999-999999999999', 'Volumen', 'room', 1, 'UTC', 240, now(), now());

INSERT INTO reservations (id, resource_id, start_at, end_at, status, created_at, updated_at)
SELECT gen_random_uuid(), '99999999-9999-4999-8999-999999999999',
       now() + (g * interval '15 minutes'),
       now() + ((g + 1) * interval '15 minutes'),
       'confirmed', now(), now()
FROM generate_series(1, 30000) AS g;

ANALYZE reservations;

EXPLAIN (ANALYZE, BUFFERS)
SELECT start_at, end_at, status
  FROM reservations
 WHERE resource_id = '99999999-9999-4999-8999-999999999999'
   AND time_range && tstzrange(now() + interval '100 days', now() + interval '100 days 3 hours', '[)')
   AND (status = 'confirmed' OR (status = 'held' AND expires_at > now()))
 ORDER BY start_at;

ROLLBACK;
```

