<?php

namespace App\Http\Resources;

use App\Models\SalaryAdjustment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SalaryAdjustment */
class SalaryAdjustmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'is_income' => $this->isIncome(),
            'amount' => (float) $this->amount,
            'signed_amount' => $this->signedAmount(),
            'description' => $this->description,
            'adjustment_date' => $this->adjustment_date?->toDateString(),
            'notes' => $this->notes,
        ];
    }
}
