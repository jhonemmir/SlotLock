<?php

namespace App\Models;

use App\Enums\WaitlistStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'resource_id',
    'user_id',
    'desired_start',
    'desired_end',
    'status',
    'promoted_reservation_id',
])]
class WaitlistEntry extends Model
{
    use HasUuids;

    /**
     * El default de Laravel formatea las fechas a segundos; la waitlist es
     * FIFO y dos entradas pueden crearse en el mismo segundo.
     */
    protected $dateFormat = 'Y-m-d H:i:s.u';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'desired_start' => 'immutable_datetime',
            'desired_end' => 'immutable_datetime',
            'status' => WaitlistStatus::class,
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

    public function promotedReservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class, 'promoted_reservation_id');
    }
}
