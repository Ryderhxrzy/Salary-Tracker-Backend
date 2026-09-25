<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A cut-off's take-home pay confirmed as received into a wallet. */
#[Fillable(['salary_period_id', 'wallet_id', 'period_from', 'period_to', 'pay_date', 'amount', 'received_date', 'notes'])]
class SalaryReceipt extends Model
{
    protected function casts(): array
    {
        return [
            'period_from' => 'date:Y-m-d',
            'period_to' => 'date:Y-m-d',
            'pay_date' => 'date:Y-m-d',
            'received_date' => 'date:Y-m-d',
            'amount' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class)->withTrashed();
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(SalaryPeriod::class, 'salary_period_id');
    }
}
