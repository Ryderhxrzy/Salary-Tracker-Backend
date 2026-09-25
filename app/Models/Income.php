<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Money earned outside the salary (side hustle, freelance, business, gift, refund). */
#[Fillable(['wallet_id', 'type', 'source', 'amount', 'income_date', 'notes'])]
class Income extends Model
{
    use SoftDeletes;

    public const TYPES = ['side_hustle', 'freelance', 'business', 'gift', 'refund', 'other'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'income_date' => 'date:Y-m-d',
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
}
