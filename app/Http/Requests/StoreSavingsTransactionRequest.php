<?php

namespace App\Http\Requests;

use App\Models\SavingsTransaction;
use App\Models\User;
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
            'savings_goal_id' => ['nullable', 'integer', Rule::exists('savings_goals', 'id')->where('user_id', $user->id)->whereNull('deleted_at')],
            // Savings always come from (or go back to) one of the user's accounts.
            'wallet_id' => [$required, 'integer', Rule::exists('wallets', 'id')->where('user_id', $user->id)->whereNull('deleted_at')],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
