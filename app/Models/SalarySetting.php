<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'salary_type', 'daily_rate', 'hourly_rate', 'weekly_rate', 'biweekly_rate', 'monthly_rate',
    'expected_hours_per_day', 'overtime_enabled', 'overtime_multiplier', 'overtime_hourly_rate',
    'prorate_undertime', 'deduct_absences',
    'period_type', 'period_start_day', 'period_second_day', 'period_start_weekday', 'period_anchor_date', 'custom_period_days',
])]
class SalarySetting extends Model
{
    public const SALARY_TYPES = ['daily', 'hourly', 'weekly', 'biweekly', 'monthly'];

    public const PERIOD_TYPES = ['weekly', 'biweekly', 'semi_monthly', 'monthly', 'custom'];

    protected function casts(): array
    {
        return [
            'daily_rate' => 'decimal:2',
            'hourly_rate' => 'decimal:2',
            'weekly_rate' => 'decimal:2',
            'biweekly_rate' => 'decimal:2',
            'monthly_rate' => 'decimal:2',
            'expected_hours_per_day' => 'decimal:2',
            'overtime_enabled' => 'boolean',
            'overtime_multiplier' => 'decimal:2',
            'overtime_hourly_rate' => 'decimal:2',
            'prorate_undertime' => 'boolean',
            'deduct_absences' => 'boolean',
            'period_start_day' => 'integer',
            'period_second_day' => 'integer',
            'period_start_weekday' => 'integer',
            'period_anchor_date' => 'date',
            'custom_period_days' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * A salary is "configured" when a type is chosen and the matching rate is set.
     */
    public function isConfigured(): bool
    {
        return $this->salary_type !== null && $this->rateForType() !== null;
    }

    public function rateForType(): ?float
    {
        $value = match ($this->salary_type) {
            'daily' => $this->daily_rate,
            'hourly' => $this->hourly_rate,
            'weekly' => $this->weekly_rate,
            'biweekly' => $this->biweekly_rate,
            'monthly' => $this->monthly_rate,
            default => null,
        };

        return $value === null ? null : (float) $value;
    }
}
