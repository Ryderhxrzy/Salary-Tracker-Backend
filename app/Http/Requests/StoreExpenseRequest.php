<?php

namespace App\Http\Requests;

use App\Models\Expense;
use Illuminate\Validation\Rule;

class StoreExpenseRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $isUpdate = $this->route('expense') !== null;
        $required = $isUpdate ? 'sometimes' : 'required';

        return [
            'amount' => [$required, 'numeric', 'min:0.01', 'max:99999999'],
            'expense_category_id' => [
                'nullable', 'integer',
                Rule::exists('expense_categories', 'id')->where('user_id', $this->user()->id),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'expense_date' => [$required, 'date_format:Y-m-d'],
            'payment_method' => ['nullable', Rule::in(Expense::PAYMENT_METHODS)],
            'wallet_id' => [
                'nullable', 'integer',
                Rule::exists('wallets', 'id')->where('user_id', $this->user()->id)->whereNull('deleted_at'),
            ],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
