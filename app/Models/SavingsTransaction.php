<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Money moved into savings (deposit) or taken back out (withdrawal), optionally
 * towards a goal and from / into a wallet.
 */
#[Fillable(['savings_goal_id', 'wallet_id', 'type', 'amount', 'transaction_date', 'notes'])]
class SavingsTransaction extends Model
{
    use SoftDeletes;

    public const TYPE_DEPOSIT = 'deposit';

    public const TYPE_WITHDRAWAL = 'withdrawal';

    public const TYPES = [self::TYPE_DEPOSIT, self::TYPE_WITHDRAWAL];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'transaction_date' => 'date:Y-m-d',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function goal(): BelongsTo
    {
        return $this->belongsTo(SavingsGoal::class, 'savings_goal_id');
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function isDeposit(): bool
    {
        return $this->type === self::TYPE_DEPOSIT;
    }

    /** Positive for deposits (money saved), negative for withdrawals. */
    public function signedAmount(): float
    {
        return $this->isDeposit() ? (float) $this->amount : -1 * (float) $this->amount;
    }
}
