<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['type', 'amount', 'description', 'adjustment_date', 'notes'])]
class SalaryAdjustment extends Model
{
    use SoftDeletes;

    public const INCOME_TYPES = ['bonus', 'commission', 'allowance', 'other_income'];

    public const DEDUCTION_TYPES = ['deduction', 'other_adjustment'];

    public const TYPES = ['bonus', 'commission', 'allowance', 'other_income', 'deduction', 'other_adjustment'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'adjustment_date' => 'date:Y-m-d',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isIncome(): bool
    {
        return in_array($this->type, self::INCOME_TYPES, true);
    }

    /** Positive for income, negative for deductions. */
    public function signedAmount(): float
    {
        return $this->isIncome() ? (float) $this->amount : -1 * (float) $this->amount;
    }
}
