<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['wallet_id', 'name', 'type', 'target_amount', 'current_amount', 'deadline', 'is_monthly', 'is_completed', 'is_shared', 'notes', 'icon', 'design'])]
class SavingsGoal extends Model
{
    use SoftDeletes;

    public const TYPES = ['savings', 'spending_limit', 'emergency_fund'];

    protected function casts(): array
    {
        return [
            'target_amount' => 'decimal:2',
            'current_amount' => 'decimal:2',
            'deadline' => 'date:Y-m-d',
            'is_monthly' => 'boolean',
            'is_completed' => 'boolean',
            'is_shared' => 'boolean',
            'design' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(SavingsTransaction::class);
    }

    /** The person who created the goal (the only one who can edit or delete it). */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** People invited to a shared goal, pending or accepted. */
    public function members(): HasMany
    {
        return $this->hasMany(SavingsGoalMember::class);
    }

    /** Owner + accepted members as users. */
    public function participants(): \Illuminate\Support\Collection
    {
        $users = collect([$this->owner]);
        foreach ($this->members()->where('status', SavingsGoalMember::STATUS_ACCEPTED)->with('user.profile')->get() as $member) {
            if ($member->user) {
                $users->push($member->user);
            }
        }

        return $users->filter()->unique('id')->values();
    }

    /** Net money one person put into this goal (deposits − withdrawals). */
    public function contributionOf(int $userId): float
    {
        return round($this->transactions()->where('user_id', $userId)->get()->sum(fn (SavingsTransaction $t) => $t->signedAmount()), 2);
    }

    /** The wallet that keeps this goal's money. */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class)->withTrashed();
    }

    public function progressPercent(): float
    {
        $target = (float) $this->target_amount;
        if ($target <= 0) {
            return 0.0;
        }

        return round(min(100, ((float) $this->current_amount / $target) * 100), 1);
    }
}
