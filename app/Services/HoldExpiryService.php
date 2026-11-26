<?php

namespace App\Services;

use App\Models\Resource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Expiración de holds.
 *
 * PostgreSQL no permite `now()` en el predicado de un índice parcial, así que
 * un hold vencido sigue bloqueando el `EXCLUDE` hasta que su fila cambia de
 * estado. La estrategia es expirar de forma oportunista en la ruta de
 * escritura (antes de insertar) y dejar un job de higiene como red de
 * seguridad. Ver docs/adr/0002-expiracion-de-holds.md.
 */
final class HoldExpiryService
{
    /**
     * Expira los holds vencidos que se solapan con el slot que se quiere
     * reservar. Se ejecuta dentro de la misma transacción del INSERT.
     */
    public function forSlot(Resource $resource, CarbonImmutable $start, CarbonImmutable $end): int
    {
        return DB::update(<<<'SQL'
            UPDATE reservations
               SET status = 'expired', updated_at = now()
             WHERE id IN (
                 SELECT id
                   FROM reservations
                  WHERE resource_id = ?::uuid
                    AND status = 'held'
                    AND expires_at <= now()
                    AND time_range && tstzrange(?::timestamptz, ?::timestamptz, '[)')
                  ORDER BY id
                    FOR UPDATE
             )
        SQL, [
            $resource->getKey(),
            $start->toIso8601String(),
            $end->toIso8601String(),
        ]);
    }

    /**
     * Barrido global para el comando programado (higiene + métricas).
     */
    public function all(): int
    {
        return DB::update(<<<'SQL'
            UPDATE reservations
               SET status = 'expired', updated_at = now()
             WHERE id IN (
                 SELECT id
                   FROM reservations
                  WHERE status = 'held'
                    AND expires_at <= now()
                  ORDER BY id
                    FOR UPDATE
             )
        SQL);
    }
}
