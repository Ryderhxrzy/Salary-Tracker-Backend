<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Validation\Rule;

class StoreLoanPaymentRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return static::rulesFor($this->user(), $this->route('payment') !== null);
    }

    public static function rulesFor(User $user, bool $isUpdate = false): array
    {
        $required = $isUpdate ? 'sometimes' : 'required';

        return [
            'amount' => [$required, 'numeric', 'min:0.01', 'max:999999999'],
            'payment_date' => [$required, 'date_format:Y-m-d'],
            'wallet_id' => ['nullable', 'integer', Rule::in(app(\App\Services\WalletSharingService::class)->accessibleWalletIds($user))],
            'via_payroll' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
