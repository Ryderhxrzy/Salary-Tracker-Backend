<?php

namespace App\Http\Requests;

use App\Models\SalarySetting;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateSalarySettingRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $money = ['nullable', 'numeric', 'min:0', 'max:99999999'];

        return [
            'salary_type' => ['nullable', Rule::in(SalarySetting::SALARY_TYPES)],
            'daily_rate' => $money,
            'hourly_rate' => $money,
            'weekly_rate' => $money,
            'biweekly_rate' => $money,
            'monthly_rate' => $money,
            'expected_hours_per_day' => ['nullable', 'numeric', 'min:0.5', 'max:24'],
            'overtime_enabled' => ['nullable', 'boolean'],
            'overtime_multiplier' => ['nullable', 'numeric', 'min:0', 'max:10'],
            'overtime_hourly_rate' => $money,
            'prorate_undertime' => ['nullable', 'boolean'],
            'deduct_absences' => ['nullable', 'boolean'],
            'period_type' => ['nullable', Rule::in(SalarySetting::PERIOD_TYPES)],
            'period_start_day' => ['nullable', 'integer', 'min:1', 'max:28'],
            'period_second_day' => ['nullable', 'integer', 'min:2', 'max:28', 'gt:period_start_day'],
            'period_start_weekday' => ['nullable', 'integer', 'min:0', 'max:6'],
            'period_anchor_date' => ['nullable', 'date_format:Y-m-d'],
            'custom_period_days' => ['nullable', 'integer', 'min:1', 'max:90'],
            'pay_delay_days' => ['nullable', 'integer', 'min:0', 'max:31'],
            'overtime_threshold_minutes' => ['nullable', 'integer', 'min:0', 'max:480'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $type = $this->input('salary_type');
                if (! $type) {
                    return;
                }
                $field = $type.'_rate';
                if ($this->input($field) === null || $this->input($field) === '') {
                    $validator->errors()->add($field, 'Please enter your '.str_replace('_', ' ', $type).' salary rate.');
                }
            },
        ];
    }
}
