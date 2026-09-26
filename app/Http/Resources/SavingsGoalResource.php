<?php

namespace App\Http\Resources;

use App\Models\SavingsGoal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SavingsGoal */
class SavingsGoalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'target_amount' => (float) $this->target_amount,
            'current_amount' => (float) $this->current_amount,
            'remaining_amount' => round(max(0, (float) $this->target_amount - (float) $this->current_amount), 2),
            'progress_percent' => $this->progressPercent(),
            'deadline' => $this->deadline?->toDateString(),
            'is_monthly' => (bool) $this->is_monthly,
            'is_completed' => (bool) $this->is_completed,
            'notes' => $this->notes,
            'icon' => $this->icon,
            'design' => $this->design,
            'is_shared' => (bool) $this->is_shared,
            'is_owner' => $request->user() ? (int) $this->user_id === (int) $request->user()->id : true,
            'owner' => $this->whenLoaded('owner', fn () => $this->owner ? ['id' => $this->owner->id, 'name' => $this->owner->profile?->nickname ?: ($this->owner->profile?->full_name ?: $this->owner->name)] : null),
            'members' => $this->whenLoaded('members', fn () => $this->members->map(fn ($m) => [
                'id' => $m->id,
                'user_id' => $m->user_id,
                'email' => $m->email,
                'name' => $m->user ? ($m->user->profile?->nickname ?: ($m->user->profile?->full_name ?: $m->user->name)) : null,
                'status' => $m->status,
                'contributed' => $m->user_id ? $this->contributionOf($m->user_id) : 0.0,
            ])->values()),
            'my_contribution' => $request->user() ? ((int) $this->user_id === (int) $request->user()->id && ! $this->is_shared ? (float) $this->current_amount : $this->contributionOf($request->user()->id)) : (float) $this->current_amount,
            'wallet_id' => $this->wallet_id,
            'wallet' => $this->whenLoaded('wallet', fn () => $this->wallet ? ['id' => $this->wallet->id, 'name' => $this->wallet->name, 'type' => $this->wallet->type] : null),
        ];
    }
}
