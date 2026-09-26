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
#[Fillable(['name', 'type', 'category', 'institution_id', 'account_type', 'last4', 'holder_name', 'color', 'design', 'opening_balance', 'balance_as_of', 'receives_salary', 'is_default', 'is_shared', 'sort_order'])]
class Wallet extends Model
{
    use SoftDeletes;

    public const TYPES = ['cash', 'gcash', 'maya', 'card', 'bank', 'other'];

    public const CATEGORIES = ['bank', 'ewallet', 'cash', 'other'];

    public const ACCOUNT_TYPES = ['savings', 'checking', 'payroll', 'debit', 'credit', 'ewallet', 'virtual_card', 'other'];

    public const DESIGN_MODES = ['solid', 'gradient'];

    public const DESIGN_DIRECTIONS = ['right', 'left', 'down', 'up', 'down-right', 'down-left', 'up-right', 'up-left'];

    public const DESIGN_PATTERNS = [
        'rings', 'orbit', 'dots', 'stripes', 'waves', 'grid', 'none',
        'bubbles', 'diagonal', 'chevron', 'diamonds', 'halftone', 'rays', 'mesh',
        'squares', 'arcs', 'confetti', 'zigzag', 'crosses', 'blob', 'ribbon', 'stars', 'hearts', 'coins', 'leaves', 'sparkles',
    ];

    protected function casts(): array
    {
        return [
            'design' => 'array',
            'is_shared' => 'boolean',
            'opening_balance' => 'decimal:2',
            'balance_as_of' => 'date:Y-m-d',
            'receives_salary' => 'boolean',
            'is_default' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** The person who created the account (the only one who can edit or delete it). */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** People invited to a shared account, pending or accepted. */
    public function members(): HasMany
    {
        return $this->hasMany(WalletMember::class);
    }

    /** Owner + accepted members as users. */
    public function participants(): \Illuminate\Support\Collection
    {
        $users = collect([$this->owner]);
        foreach ($this->members()->where('status', WalletMember::STATUS_ACCEPTED)->with('user.profile')->get() as $member) {
            if ($member->user) {
                $users->push($member->user);
            }
        }

        return $users->filter()->unique('id')->values();
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

    public function transfersOut(): HasMany
    {
        return $this->hasMany(WalletTransfer::class, 'from_wallet_id');
    }

    public function transfersIn(): HasMany
    {
        return $this->hasMany(WalletTransfer::class, 'to_wallet_id');
    }
}
