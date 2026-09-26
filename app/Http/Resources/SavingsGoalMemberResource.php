<?php

namespace App\Http\Resources;

use App\Models\SavingsGoalMember;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SavingsGoalMember */
class SavingsGoalMemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $this->whenLoaded('user', fn () => $this->user);
        $goal = $this->whenLoaded('goal', fn () => $this->goal);
        $inviter = $this->whenLoaded('inviter', fn () => $this->inviter);

        return [
            'id' => $this->id,
            'goal_id' => $this->savings_goal_id,
            'user_id' => $this->user_id,
            'email' => $this->email,
            'name' => $this->user ? ($this->user->profile?->nickname ?: ($this->user->profile?->full_name ?: $this->user->name)) : null,
            'role' => $this->role,
            'status' => $this->status,
            'accepted_at' => $this->accepted_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'contributed' => (float) ($this->contributed ?? 0),
            'goal' => $goal instanceof \App\Models\SavingsGoal ? [
                'id' => $goal->id,
                'name' => $goal->name,
                'type' => $goal->type,
                'target_amount' => (float) $goal->target_amount,
                'current_amount' => (float) $goal->current_amount,
                'owner_name' => $goal->owner ? ($goal->owner->profile?->nickname ?: ($goal->owner->profile?->full_name ?: $goal->owner->name)) : null,
            ] : null,
            'invited_by_name' => $inviter instanceof \App\Models\User ? ($inviter->profile?->nickname ?: ($inviter->profile?->full_name ?: $inviter->name)) : null,
        ];
    }
}
