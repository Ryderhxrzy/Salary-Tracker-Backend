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
            'wallet_id' => $this->wallet_id,
            'wallet' => $this->whenLoaded('wallet', fn () => $this->wallet ? ['id' => $this->wallet->id, 'name' => $this->wallet->name, 'type' => $this->wallet->type] : null),
        ];
    }
}
