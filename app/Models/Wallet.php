<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A place where money sits: cash on hand, GCash, Maya, a card or a bank account.
 * Balances are computed (opening balance + salary received − expenses − savings).
 */
#[Fillable(['name', 'type', 'category', 'institution_id', 'account_type', 'last4', 'holder_name', 'color', 'opening_balance', 'balance_as_of', 'receives_salary', 'is_default', 'sort_order'])]
class Wallet extends Model
{
    use SoftDeletes;

    public const TYPES = ['cash', 'gcash', 'maya', 'card', 'bank', 'other'];

    public const CATEGORIES = ['bank', 'ewallet', 'cash', 'other'];

    public const ACCOUNT_TYPES = ['savings', 'checking', 'payroll', 'debit', 'credit', 'ewallet', 'virtual_card', 'other'];

    protected function casts(): array
    {
        return [
            'opening_balance' => 'decimal:2',
            'balance_as_of' => 'date:Y-m-d',
            'receives_salary' => 'boolean',
            'is_default' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function savingsTransactions(): HasMany
    {
        return $this->hasMany(SavingsTransaction::class);
    }
}
