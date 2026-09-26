<?php

namespace App\Http\Requests;

use App\Models\Loan;
use App\Models\User;
use Illuminate\Validation\Rule;

class StoreLoanRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return static::rulesFor($this->user(), $this->route('loan') !== null);
    }

    public static function rulesFor(User $user, bool $isUpdate = false): array
    {
        $required = $isUpdate ? 'sometimes' : 'required';
        $money = ['numeric', 'min:0', 'max:999999999'];

        return [
            'name' => [$required, 'string', 'max:120'],
            'lender' => ['nullable', 'string', 'max:120'],
            'type' => ['nullable', Rule::in(Loan::TYPES)],
            'principal_amount' => [$required, 'numeric', 'min:0.01', 'max:999999999'],
            'total_amount' => ['nullable', ...$money],
            'opening_paid_amount' => ['nullable', ...$money],
            'installment_amount' => ['nullable', ...$money],
            'frequency' => ['nullable', Rule::in(Loan::FREQUENCIES)],
            'start_date' => [$required, 'date_format:Y-m-d'],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'next_due_date' => ['nullable', 'date_format:Y-m-d'],
            'wallet_id' => ['nullable', 'integer', Rule::in(app(\App\Services\WalletSharingService::class)->accessibleWalletIds($user))],
            'via_payroll' => ['nullable', 'boolean'],
            'is_closed' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
