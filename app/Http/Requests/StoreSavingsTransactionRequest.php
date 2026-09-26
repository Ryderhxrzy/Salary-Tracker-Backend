<?php

namespace App\Http\Requests;

use App\Models\SavingsTransaction;
use App\Models\User;
use App\Services\GoalSharingService;
use Illuminate\Validation\Rule;

class StoreSavingsTransactionRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return static::rulesFor($this->user(), $this->route('transaction') !== null);
    }

    public static function rulesFor(User $user, bool $isUpdate = false): array
    {
        $required = $isUpdate ? 'sometimes' : 'required';

        return [
            'type' => ['nullable', Rule::in(SavingsTransaction::TYPES)],
            'amount' => [$required, 'numeric', 'min:0.01', 'max:999999999'],
            'transaction_date' => [$required, 'date_format:Y-m-d'],
            // Own goals and the shared goals the user accepted.
            'savings_goal_id' => ['nullable', 'integer', Rule::in(app(GoalSharingService::class)->contributableGoalIds($user))],
            // Savings always come from (or go back to) one of the user's accounts.
            'wallet_id' => [$required, 'integer', Rule::in(app(\App\Services\WalletSharingService::class)->accessibleWalletIds($user))],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
