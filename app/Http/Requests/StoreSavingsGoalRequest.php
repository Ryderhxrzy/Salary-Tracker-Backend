<?php

namespace App\Http\Requests;

use App\Models\SavingsGoal;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Validation\Rule;

class StoreSavingsGoalRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return static::rulesFor($this->user(), $this->route('goal') !== null);
    }

    public static function rulesFor(User $user, bool $isUpdate = false): array
    {
        $required = $isUpdate ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:120'],
            'type' => ['nullable', Rule::in(SavingsGoal::TYPES)],
            'wallet_id' => ['nullable', 'integer', Rule::exists('wallets', 'id')->where('user_id', $user->id)->whereNull('deleted_at')],
            'target_amount' => [$required, 'numeric', 'min:0.01', 'max:999999999'],
            'current_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'deadline' => ['nullable', 'date_format:Y-m-d'],
            'is_monthly' => ['nullable', 'boolean'],
            'is_completed' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'icon' => ['nullable', 'string', 'max:60', 'regex:/^[a-z0-9-]+$/'],
            // Same shape as a wallet's card design.
            'design' => ['nullable', 'array'],
            'design.mode' => ['required_with:design', Rule::in(Wallet::DESIGN_MODES)],
            'design.colors' => ['required_with:design', 'array', 'min:1', 'max:3'],
            'design.colors.*' => ['string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'design.direction' => ['required_with:design', Rule::in(Wallet::DESIGN_DIRECTIONS)],
            'design.pattern' => ['required_with:design', Rule::in(Wallet::DESIGN_PATTERNS)],
        ];
    }
}
