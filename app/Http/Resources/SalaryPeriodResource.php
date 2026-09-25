<?php

namespace App\Http\Resources;

use App\Models\SalaryPeriod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SalaryPeriod */
class SalaryPeriodResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'period_type' => $this->period_type,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'pay_date' => $this->pay_date,
            // upcoming | ongoing | completed (waiting for payday) | paid
            'status' => $this->period_status ?? $this->status,
            'summary' => $this->when(isset($this->summary), fn () => $this->summary),
        ];
    }
}
