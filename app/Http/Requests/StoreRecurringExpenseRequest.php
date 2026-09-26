<?php

namespace App\Http\Requests;

use App\Models\RecurringExpense;
use App\Models\User;
use Illuminate\Validation\Rule;

class StoreRecurringExpenseRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return static::rulesFor($this->user(), $this->route('recurringExpense') !== null);
    }

    public static function rulesFor(User $user, bool $isUpdate = false): array
    {
        $required = $isUpdate ? 'sometimes' : 'required';

        return [
            'description' => [$required, 'string', 'max:120'],
            'amount' => [$required, 'numeric', 'min:0.01', 'max:99999999'],
            'expense_category_id' => ['nullable', 'integer', Rule::exists('expense_categories', 'id')->where('user_id', $user->id)],
            // Which account the bill is taken from.
            'wallet_id' => ['nullable', 'integer', Rule::in(app(\App\Services\WalletSharingService::class)->accessibleWalletIds($user))],
            'frequency' => [$required, Rule::in(RecurringExpense::FREQUENCIES)],
            'day_of_month' => ['nullable', 'integer', 'min:1', 'max:31'],
            'second_day_of_month' => ['nullable', 'integer', 'min:1', 'max:31'],
            'weekday' => ['nullable', 'integer', 'min:0', 'max:6'],
            'month_of_year' => ['nullable', 'integer', 'min:1', 'max:12'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'auto_pay' => ['nullable', 'boolean'],
            'remind' => ['nullable', 'boolean'],
            'remind_days_before' => ['nullable', 'integer', 'min:0', 'max:14'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
