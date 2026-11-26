<?php

namespace App\Models;

use App\Enums\ReservationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['resource_id', 'user_id', 'start_at', 'end_at', 'status', 'expires_at'])]
class Reservation extends Model
{
    use HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_at' => 'immutable_datetime',
            'end_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'status' => ReservationStatus::class,
        ];
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Reservas que ocupan el slot ahora: `confirmed` o `held` no vencidos.
     */
    public function scopeBlocking(Builder $query): void
    {
        $query->where(function (Builder $query) {
            $query->where('status', ReservationStatus::Confirmed->value)
                ->orWhere(function (Builder $query) {
                    $query->where('status', ReservationStatus::Held->value)
                        ->whereRaw('expires_at > now()');
                });
        });
    }
}
