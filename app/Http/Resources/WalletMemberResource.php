<?php

namespace App\Http\Resources;

use App\Models\WalletMember;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin WalletMember */
class WalletMemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $wallet = $this->whenLoaded('wallet', fn () => $this->wallet);
        $inviter = $this->whenLoaded('inviter', fn () => $this->inviter);

        return [
            'id' => $this->id,
            'wallet_id' => $this->wallet_id,
            'user_id' => $this->user_id,
            'email' => $this->email,
            'name' => $this->user ? ($this->user->profile?->nickname ?: ($this->user->profile?->full_name ?: $this->user->name)) : null,
            'role' => $this->role,
            'status' => $this->status,
            'accepted_at' => $this->accepted_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'wallet' => $wallet instanceof \App\Models\Wallet ? [
                'id' => $wallet->id,
                'name' => $wallet->name,
                'type' => $wallet->type,
                'institution_id' => $wallet->institution_id,
                'owner_name' => $wallet->owner ? ($wallet->owner->profile?->nickname ?: ($wallet->owner->profile?->full_name ?: $wallet->owner->name)) : null,
            ] : null,
            'invited_by_name' => $inviter instanceof \App\Models\User ? ($inviter->profile?->nickname ?: ($inviter->profile?->full_name ?: $inviter->name)) : null,
        ];
    }
}
