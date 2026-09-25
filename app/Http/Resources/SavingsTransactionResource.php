<?php

namespace App\Http\Resources;

use App\Models\SavingsTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SavingsTransaction */
class SavingsTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'amount' => (float) $this->amount,
            'signed_amount' => $this->signedAmount(),
            'transaction_date' => $this->transaction_date?->toDateString(),
            'notes' => $this->notes,
            'goal_id' => $this->savings_goal_id,
            'goal' => $this->whenLoaded('goal', fn () => $this->goal ? ['id' => $this->goal->id, 'name' => $this->goal->name, 'type' => $this->goal->type] : null),
            'wallet_id' => $this->wallet_id,
            'wallet' => $this->whenLoaded('wallet', fn () => $this->wallet ? ['id' => $this->wallet->id, 'name' => $this->wallet->name, 'type' => $this->wallet->type] : null),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
