<?php

namespace App\Http\Resources;

use App\Models\WalletTransfer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin WalletTransfer */
class WalletTransferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $ref = fn ($wallet) => $wallet ? ['id' => $wallet->id, 'name' => $wallet->name, 'type' => $wallet->type] : null;

        return [
            'id' => $this->id,
            'from_wallet_id' => $this->from_wallet_id,
            'to_wallet_id' => $this->to_wallet_id,
            'from_wallet' => $this->whenLoaded('fromWallet', fn () => $ref($this->fromWallet)),
            'to_wallet' => $this->whenLoaded('toWallet', fn () => $ref($this->toWallet)),
            'amount' => (float) $this->amount,
            'fee' => (float) $this->fee,
            'transfer_date' => $this->transfer_date?->toDateString(),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
