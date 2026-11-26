<?php

namespace App\Http\Resources;

use App\Models\WaitlistEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin WaitlistEntry */
class WaitlistEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'resource_id' => $this->resource_id,
            'user_id' => $this->user_id,
            'desired_start' => $this->desired_start->toIso8601String(),
            'desired_end' => $this->desired_end->toIso8601String(),
            'status' => $this->status->value,
            'promoted_reservation_id' => $this->promoted_reservation_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
