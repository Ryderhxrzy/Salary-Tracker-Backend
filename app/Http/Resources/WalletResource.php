<?php

namespace App\Http\Resources;

use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Wallet */
class WalletResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'category' => $this->category ?? 'other',
            'institution_id' => $this->institution_id,
            'account_type' => $this->account_type,
            'last4' => $this->last4,
            'holder_name' => $this->holder_name,
            'color' => $this->color,
            'design' => $this->design,
            'opening_balance' => (float) $this->opening_balance,
            'balance_as_of' => $this->balance_as_of?->toDateString(),
            // The owner's flags mean nothing to a member using a shared account.
            'receives_salary' => $this->isOwner($request) && (bool) $this->receives_salary,
            'is_default' => $this->isOwner($request) && (bool) $this->is_default,
            'sort_order' => (int) $this->sort_order,
            'is_shared' => (bool) $this->is_shared,
            'is_owner' => $this->isOwner($request),
            'owner' => $this->whenLoaded('owner', fn () => $this->owner ? ['id' => $this->owner->id, 'name' => $this->owner->profile?->nickname ?: ($this->owner->profile?->full_name ?: $this->owner->name)] : null),
            'members' => $this->whenLoaded('members', fn () => $this->members->map(fn ($m) => [
                'id' => $m->id,
                'user_id' => $m->user_id,
                'email' => $m->email,
                'name' => $m->user ? ($m->user->profile?->nickname ?: ($m->user->profile?->full_name ?: $m->user->name)) : null,
                'status' => $m->status,
            ])->values()),
            // Set by WalletService::withBalances(); absent on plain CRUD responses.
            'balance' => $this->when(isset($this->balance), fn () => (float) $this->balance),
            'salary_received' => $this->when(isset($this->salary_received), fn () => (float) $this->salary_received),
            'spent' => $this->when(isset($this->spent), fn () => (float) $this->spent),
            'saved' => $this->when(isset($this->saved), fn () => (float) $this->saved),
            'loans' => $this->when(isset($this->loans), fn () => (float) $this->loans),
            'other_income' => $this->when(isset($this->other_income), fn () => (float) $this->other_income),
            'transfers_in' => $this->when(isset($this->transfers_in), fn () => (float) $this->transfers_in),
            'transfers_out' => $this->when(isset($this->transfers_out), fn () => (float) $this->transfers_out),
            'goals_in' => $this->when(isset($this->goals_in), fn () => (float) $this->goals_in),
            'goals_held' => $this->when(isset($this->goals_held), fn () => (float) $this->goals_held),
            'available' => $this->when(isset($this->available), fn () => (float) $this->available),
        ];
    }

    private function isOwner(Request $request): bool
    {
        $user = $request->user();

        return $user === null || (int) $this->user_id === (int) $user->id;
    }
}
