<?php

namespace App\Http\Resources;

use App\Models\LoanPayment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LoanPayment */
class LoanPaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'loan_id' => $this->loan_id,
            'loan' => $this->whenLoaded('loan', fn () => $this->loan ? ['id' => $this->loan->id, 'name' => $this->loan->name, 'type' => $this->loan->type] : null),
            'amount' => (float) $this->amount,
            'payment_date' => $this->payment_date?->toDateString(),
            'wallet_id' => $this->wallet_id,
            'wallet' => $this->whenLoaded('wallet', fn () => $this->wallet ? ['id' => $this->wallet->id, 'name' => $this->wallet->name, 'type' => $this->wallet->type] : null),
            'via_payroll' => (bool) $this->via_payroll,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
