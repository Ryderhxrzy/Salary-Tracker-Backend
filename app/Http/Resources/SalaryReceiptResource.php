<?php

namespace App\Http\Resources;

use App\Models\SalaryReceipt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SalaryReceipt */
class SalaryReceiptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'period_from' => $this->period_from?->toDateString(),
            'period_to' => $this->period_to?->toDateString(),
            'pay_date' => $this->pay_date?->toDateString(),
            'amount' => (float) $this->amount,
            'received_date' => $this->received_date?->toDateString(),
            'wallet_id' => $this->wallet_id,
            'wallet' => $this->whenLoaded('wallet', fn () => $this->wallet ? ['id' => $this->wallet->id, 'name' => $this->wallet->name, 'type' => $this->wallet->type] : null),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
