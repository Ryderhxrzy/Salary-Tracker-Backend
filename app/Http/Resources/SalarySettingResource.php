<?php

namespace App\Http\Resources;

use App\Models\SalarySetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SalarySetting */
class SalarySettingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $money = fn ($v) => $v === null ? null : (float) $v;

        return [
            'configured' => $this->isConfigured(),
            'salary_type' => $this->salary_type,
            'basic_salary' => $money($this->basic_salary),
            'daily_rate' => $money($this->daily_rate),
            'hourly_rate' => $money($this->hourly_rate),
            'weekly_rate' => $money($this->weekly_rate),
            'biweekly_rate' => $money($this->biweekly_rate),
            'monthly_rate' => $money($this->monthly_rate),
            'expected_hours_per_day' => (float) $this->expected_hours_per_day,
            'overtime_enabled' => (bool) $this->overtime_enabled,
            'overtime_multiplier' => (float) $this->overtime_multiplier,
            'overtime_hourly_rate' => $money($this->overtime_hourly_rate),
            'prorate_undertime' => (bool) $this->prorate_undertime,
            'deduct_absences' => (bool) $this->deduct_absences,
            'period_type' => $this->period_type,
            'period_start_day' => $this->period_start_day,
            'period_second_day' => $this->period_second_day,
            'period_start_weekday' => $this->period_start_weekday,
            'period_anchor_date' => $this->period_anchor_date?->toDateString(),
            'custom_period_days' => $this->custom_period_days,
            'pay_delay_days' => $this->pay_delay_days,
            'overtime_threshold_minutes' => $this->overtime_threshold_minutes,
        ];
    }
}
