<?php

namespace App\Http\Requests;

use App\Models\SalaryAdjustment;
use Illuminate\Validation\Rule;

class StoreSalaryAdjustmentRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return static::rulesFor($this->route('adjustment') !== null);
    }

    public static function rulesFor(bool $isUpdate = false): array
    {
        $required = $isUpdate ? 'sometimes' : 'required';

        return [
            'type' => [$required, Rule::in(SalaryAdjustment::TYPES)],
            'amount' => [$required, 'numeric', 'min:0.01', 'max:99999999'],
            'description' => ['nullable', 'string', 'max:255'],
            'adjustment_date' => [$required, 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
