<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Money moved between two of the user's wallets (cash-in to GCash, ATM withdrawal…).
 * `amount` leaves the source and lands in the destination; `fee` leaves the source only.
 */
#[Fillable(['from_wallet_id', 'to_wallet_id', 'amount', 'fee', 'transfer_date', 'notes'])]
class WalletTransfer extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'fee' => 'decimal:2',
            'transfer_date' => 'date:Y-m-d',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function fromWallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'from_wallet_id')->withTrashed();
    }

    public function toWallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'to_wallet_id')->withTrashed();
    }

    /** Everything that leaves the source wallet. */
    public function totalOut(): float
    {
        return (float) $this->amount + (float) $this->fee;
    }
}
