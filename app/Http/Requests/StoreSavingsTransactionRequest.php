<?php

namespace App\Http\Requests;

use App\Models\SavingsTransaction;
use Illuminate\Validation\Rule;

class StoreSavingsTransactionRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $isUpdate = $this->route('transaction') !== null;
        $required = $isUpdate ? 'sometimes' : 'required';
        $userId = $this->user()->id;

        return [
            'type' => ['nullable', Rule::in(SavingsTransaction::TYPES)],
            'amount' => [$required, 'numeric', 'min:0.01', 'max:999999999'],
            'transaction_date' => [$required, 'date_format:Y-m-d'],
            'savings_goal_id' => ['nullable', 'integer', Rule::exists('savings_goals', 'id')->where('user_id', $userId)->whereNull('deleted_at')],
            'wallet_id' => ['nullable', 'integer', Rule::exists('wallets', 'id')->where('user_id', $userId)->whereNull('deleted_at')],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
