<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['expense_category_id', 'wallet_id', 'recurring_expense_id', 'amount', 'description', 'expense_date', 'payment_method', 'notes'])]
class Expense extends Model
{
    use SoftDeletes;

    public const PAYMENT_METHODS = ['cash', 'gcash', 'maya', 'card', 'bank', 'other'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'expense_date' => 'date:Y-m-d',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    /** The wallet (cash, GCash, bank...) the expense was paid from. */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }
}
