<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Validation\Rule;

class StoreSalaryReceiptRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return static::rulesFor($this->user());
    }

    public static function rulesFor(User $user): array
    {
        return [
            'period_from' => ['required', 'date_format:Y-m-d'],
            'period_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_from'],
            // Defaults to the wallet that receives the salary.
            'wallet_id' => ['nullable', 'integer', Rule::exists('wallets', 'id')->where('user_id', $user->id)->whereNull('deleted_at')],
            // Defaults to the computed take-home pay of the cut-off.
            'amount' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'received_date' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
