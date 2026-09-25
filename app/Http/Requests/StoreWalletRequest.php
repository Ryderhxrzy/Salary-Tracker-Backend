<?php

namespace App\Http\Requests;

use App\Models\Wallet;
use Illuminate\Validation\Rule;

class StoreWalletRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return static::rulesFor($this->route('wallet') !== null);
    }

    public static function rulesFor(bool $isUpdate = false): array
    {
        $required = $isUpdate ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:60'],
            'type' => ['nullable', Rule::in(Wallet::TYPES)],
            'category' => ['nullable', Rule::in(Wallet::CATEGORIES)],
            'institution_id' => ['nullable', 'string', 'max:40', 'regex:/^[a-z0-9_-]+$/'],
            'account_type' => ['nullable', Rule::in(Wallet::ACCOUNT_TYPES)],
            'last4' => ['nullable', 'string', 'regex:/^[0-9]{4}$/'],
            'holder_name' => ['nullable', 'string', 'max:100'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            // Card look chosen by the user; null resets to the institution look.
            'design' => ['nullable', 'array'],
            'design.mode' => ['required_with:design', Rule::in(Wallet::DESIGN_MODES)],
            'design.colors' => ['required_with:design', 'array', 'min:1', 'max:3'],
            'design.colors.*' => ['string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'design.direction' => ['required_with:design', Rule::in(Wallet::DESIGN_DIRECTIONS)],
            'design.pattern' => ['required_with:design', Rule::in(Wallet::DESIGN_PATTERNS)],
            'opening_balance' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'balance_as_of' => ['nullable', 'date_format:Y-m-d'],
            'receives_salary' => ['nullable', 'boolean'],
            'is_default' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ];
    }
}
