<?php

namespace App\Http\Requests;

use App\Models\SavingsGoal;
use Illuminate\Validation\Rule;

class StoreSavingsGoalRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return static::rulesFor($this->route('goal') !== null);
    }

    public static function rulesFor(bool $isUpdate = false): array
    {
        $required = $isUpdate ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:120'],
            'type' => ['nullable', Rule::in(SavingsGoal::TYPES)],
            'target_amount' => [$required, 'numeric', 'min:0.01', 'max:999999999'],
            'current_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'deadline' => ['nullable', 'date_format:Y-m-d'],
            'is_monthly' => ['nullable', 'boolean'],
            'is_completed' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
