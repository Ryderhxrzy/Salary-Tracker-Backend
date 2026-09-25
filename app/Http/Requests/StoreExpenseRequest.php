<?php

namespace App\Http\Requests;

use App\Models\Expense;
use App\Models\User;
use Illuminate\Validation\Rule;

class StoreExpenseRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return static::rulesFor($this->user(), $this->route('expense') !== null);
    }

    public static function rulesFor(User $user, bool $isUpdate = false): array
    {
        $required = $isUpdate ? 'sometimes' : 'required';

        return [
            'amount' => [$required, 'numeric', 'min:0.01', 'max:99999999'],
            'expense_category_id' => [
                'nullable', 'integer',
                Rule::exists('expense_categories', 'id')->where('user_id', $user->id),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'expense_date' => [$required, 'date_format:Y-m-d'],
            'payment_method' => ['nullable', Rule::in(Expense::PAYMENT_METHODS)],
            'wallet_id' => [
                'nullable', 'integer',
                Rule::exists('wallets', 'id')->where('user_id', $user->id)->whereNull('deleted_at'),
            ],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
