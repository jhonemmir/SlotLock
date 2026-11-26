<?php

namespace App\Enums;

enum ReservationStatus: string
{
    case Held = 'held';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    /**
     * Estados que bloquean el slot para nuevas reservas.
     */
    public function blocksSlot(): bool
    {
        return $this === self::Held || $this === self::Confirmed;
    }
}
