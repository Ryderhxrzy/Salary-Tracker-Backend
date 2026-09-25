<?php

namespace App\Http\Resources;

use App\Models\LeaveRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LeaveRecord */
class LeaveRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'leave_date' => $this->leave_date?->toDateString(),
            'type' => $this->type,
            'is_paid' => (bool) $this->is_paid,
            'notes' => $this->notes,
        ];
    }
}
