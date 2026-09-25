<?php

namespace App\Http\Requests;

use App\Models\Wallet;
use Illuminate\Validation\Rule;

class StoreWalletRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $isUpdate = $this->route('wallet') !== null;
        $required = $isUpdate ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:60'],
            'type' => ['nullable', Rule::in(Wallet::TYPES)],
            'opening_balance' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'balance_as_of' => ['nullable', 'date_format:Y-m-d'],
            'receives_salary' => ['nullable', 'boolean'],
            'is_default' => ['nullable', 'boolean'],
        ];
    }
}
