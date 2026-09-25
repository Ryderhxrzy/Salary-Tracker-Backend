<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A loan: money the user owes (borrowed) or money owed to the user (lent).
 *
 *   paid      = opening paid amount + every recorded payment
 *   remaining = total amount − paid
 */
#[Fillable([
    'name', 'lender', 'type', 'principal_amount', 'total_amount', 'opening_paid_amount', 'installment_amount',
    'frequency', 'start_date', 'due_date', 'next_due_date', 'wallet_id', 'via_payroll', 'is_closed', 'notes',
])]
class Loan extends Model
{
    use SoftDeletes;

    public const TYPE_BORROWED = 'borrowed';

    public const TYPE_LENT = 'lent';

    public const TYPES = [self::TYPE_BORROWED, self::TYPE_LENT];

    public const FREQUENCIES = ['per_cutoff', 'monthly', 'weekly', 'none'];

    protected function casts(): array
    {
        return [
            'principal_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'opening_paid_amount' => 'decimal:2',
            'installment_amount' => 'decimal:2',
            'start_date' => 'date:Y-m-d',
            'due_date' => 'date:Y-m-d',
            'next_due_date' => 'date:Y-m-d',
            'via_payroll' => 'boolean',
            'is_closed' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(LoanPayment::class)->orderByDesc('payment_date')->orderByDesc('id');
    }

    public function isBorrowed(): bool
    {
        return $this->type === self::TYPE_BORROWED;
    }

    /** Sum of recorded payments; uses the `payments_sum_amount` aggregate when it was loaded. */
    public function paymentsTotal(): float
    {
        if (array_key_exists('payments_sum_amount', $this->attributes)) {
            return (float) ($this->attributes['payments_sum_amount'] ?? 0);
        }

        return (float) $this->payments()->sum('amount');
    }

    public function paidAmount(): float
    {
        return round((float) $this->opening_paid_amount + $this->paymentsTotal(), 2);
    }

    public function remainingAmount(): float
    {
        return round(max(0.0, (float) $this->total_amount - $this->paidAmount()), 2);
    }

    public function isPaid(): bool
    {
        return $this->remainingAmount() <= 0.0;
    }

    public function progressPercent(): float
    {
        $total = (float) $this->total_amount;
        if ($total <= 0) {
            return 100.0;
        }

        return round(min(100, $this->paidAmount() / $total * 100), 1);
    }
}
