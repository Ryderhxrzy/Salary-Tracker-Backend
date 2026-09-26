<?php

namespace App\Http\Requests;

use App\Models\Income;
use App\Models\User;
use Illuminate\Validation\Rule;

class StoreIncomeRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return static::rulesFor($this->user(), $this->route('income') !== null);
    }

    public static function rulesFor(User $user, bool $isUpdate = false): array
    {
        $required = $isUpdate ? 'sometimes' : 'required';

        return [
            'amount' => [$required, 'numeric', 'min:0.01', 'max:999999999'],
            'income_date' => [$required, 'date_format:Y-m-d'],
            'type' => ['nullable', Rule::in(Income::TYPES)],
            'source' => ['nullable', 'string', 'max:120'],
            'wallet_id' => ['nullable', 'integer', Rule::in(app(\App\Services\WalletSharingService::class)->accessibleWalletIds($user))],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
