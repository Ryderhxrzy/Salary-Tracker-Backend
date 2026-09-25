<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\SalarySetting;
use App\Models\User;
use App\Support\Money;

/**
 * Rate resolution and per-day pay computation. Never invents a salary: every
 * method returns null when the user has not configured one.
 *
 * All rates derive from one monthly equivalent, so a basic salary per cut-off,
 * a monthly salary or a daily rate always agree with each other:
 *
 *   basic ₱10,000 per semi-monthly cut-off  =>  ₱20,000 / month
 *   daily  = 20,000 / (6 working days × 52 / 12 = 26)  =  ₱769.23
 *   hourly = 769.23 / 8 = ₱96.15, overtime = hourly × 1.25 (or a custom rate)
 */
class SalaryService
{
    /** Salary types paid as a fixed amount per pay period regardless of how many working days it has. */
    public const FIXED_TYPES = ['per_period', 'weekly', 'biweekly', 'monthly'];

    public function __construct(protected WorkScheduleService $schedules) {}

    public function settings(User $user): SalarySetting
    {
        $settings = $user->relationLoaded('salarySetting') && $user->salarySetting
            ? $user->salarySetting
            : $user->salarySetting()->firstOrCreate([]);
        if ($settings->wasRecentlyCreated) {
            $settings = $settings->fresh(); // pick up database defaults
        }
        $user->setRelation('salarySetting', $settings);

        return $settings;
    }

    public function isConfigured(User $user): bool
    {
        return $this->settings($user)->isConfigured();
    }

    /**
     * How many pay periods there are in a month for the configured period type.
     */
    public function periodsPerMonth(SalarySetting $settings): float
    {
        return match ($settings->period_type) {
            'weekly' => 52 / 12,
            'biweekly' => 26 / 12,
            'semi_monthly' => 2.0,
            'custom' => (365.25 / max(1, (int) $settings->custom_period_days)) / 12,
            default => 1.0,
        };
    }

    /**
     * Working days in an average month (6-day week => 26).
     */
    public function workingDaysPerMonth(User $user): float
    {
        return $this->schedules->workingDaysPerWeek($user) * 52 / 12;
    }

    /**
     * The configured salary expressed per month.
     */
    public function monthlyEquivalent(User $user, ?SalarySetting $settings = null): ?float
    {
        $settings ??= $this->settings($user);
        if (! $settings->isConfigured()) {
            return null;
        }

        $hours = max(0.01, (float) $settings->expected_hours_per_day);
        $daysPerMonth = $this->workingDaysPerMonth($user);

        return match ($settings->salary_type) {
            'per_period' => (float) $settings->basic_salary * $this->periodsPerMonth($settings),
            'daily' => (float) $settings->daily_rate * $daysPerMonth,
            'hourly' => (float) $settings->hourly_rate * $hours * $daysPerMonth,
            'weekly' => (float) $settings->weekly_rate * 52 / 12,
            'biweekly' => (float) $settings->biweekly_rate * 26 / 12,
            'monthly' => (float) $settings->monthly_rate,
            default => null,
        };
    }

    /**
     * Effective daily rate derived from whichever salary type is configured.
     */
    public function dailyRate(User $user, ?SalarySetting $settings = null): ?float
    {
        $settings ??= $this->settings($user);
        if (! $settings->isConfigured()) {
            return null;
        }

        // Time-based types are already a daily amount; avoid rounding through the monthly figure.
        $hours = max(0.01, (float) $settings->expected_hours_per_day);
        if ($settings->salary_type === 'daily') {
            return (float) $settings->daily_rate;
        }
        if ($settings->salary_type === 'hourly') {
            return (float) $settings->hourly_rate * $hours;
        }

        $monthly = $this->monthlyEquivalent($user, $settings);

        return $monthly === null ? null : $monthly / $this->workingDaysPerMonth($user);
    }

    /**
     * Effective hourly rate derived from whichever salary type is configured.
     */
    public function hourlyRate(User $user, ?SalarySetting $settings = null): ?float
    {
        $settings ??= $this->settings($user);
        if (! $settings->isConfigured()) {
            return null;
        }
        if ($settings->salary_type === 'hourly') {
            return (float) $settings->hourly_rate;
        }

        $daily = $this->dailyRate($user, $settings);

        return $daily === null ? null : $daily / max(0.01, (float) $settings->expected_hours_per_day);
    }

    public function overtimeHourlyRate(User $user, ?SalarySetting $settings = null): ?float
    {
        $settings ??= $this->settings($user);
        if (! $settings->isConfigured() || ! $settings->overtime_enabled) {
            return null;
        }
        if ($settings->overtime_hourly_rate !== null) {
            return (float) $settings->overtime_hourly_rate;
        }
        $hourly = $this->hourlyRate($user, $settings);

        return $hourly === null ? null : $hourly * (float) $settings->overtime_multiplier;
    }

    /**
     * Basic pay of one pay period before any deduction or overtime.
     *
     * Fixed types pay the same amount every period (₱10,000 per cut-off whether it
     * has 13 or 14 working days). Time-based types (daily, hourly) are the daily
     * rate times the working days scheduled in that period.
     */
    public function basicForPeriod(User $user, int $workingDays, ?SalarySetting $settings = null): ?float
    {
        $settings ??= $this->settings($user);
        if (! $settings->isConfigured()) {
            return null;
        }

        if ($settings->salary_type === 'per_period') {
            return Money::round($settings->basic_salary);
        }
        if (in_array($settings->salary_type, self::FIXED_TYPES, true)) {
            return Money::round(($this->monthlyEquivalent($user, $settings) ?? 0.0) / $this->periodsPerMonth($settings));
        }

        return Money::round(($this->dailyRate($user, $settings) ?? 0.0) * $workingDays);
    }

    /**
     * Compute pay for an attendance record given its minutes.
     *
     * @return array{regular_amount: ?float, overtime_amount: ?float, salary_amount: ?float}
     */
    public function amountsFor(User $user, AttendanceRecord $record, int $expectedMinutes, ?SalarySetting $settings = null): array
    {
        $settings ??= $this->settings($user);
        $none = ['regular_amount' => null, 'overtime_amount' => null, 'salary_amount' => null];

        if (! $settings->isConfigured()) {
            return $none;
        }

        // Only days actually worked earn pay (leave/absent/rest are handled by period totals).
        if ($record->time_in === null || $record->worked_minutes <= 0) {
            if (! in_array($record->status, [AttendanceRecord::STATUS_PRESENT, AttendanceRecord::STATUS_LATE], true)) {
                return $none;
            }
        }

        $regular = 0.0;
        if ($settings->salary_type === 'hourly') {
            $regular = (float) $settings->hourly_rate * ($record->regular_minutes / 60);
        } else {
            // Day-based pay is earned per hour actually worked: daily rate / expected hours × hours.
            $daily = $this->dailyRate($user, $settings) ?? 0.0;
            $expected = $expectedMinutes > 0 ? $expectedMinutes : (int) round((float) $settings->expected_hours_per_day * 60);
            $regular = $expected > 0 ? $daily * min(1, $record->regular_minutes / $expected) : 0.0;
        }

        $overtime = 0.0;
        if ($settings->overtime_enabled && $record->overtime_minutes > 0) {
            $overtime = ($this->overtimeHourlyRate($user, $settings) ?? 0.0) * ($record->overtime_minutes / 60);
        }

        return [
            'regular_amount' => Money::round($regular),
            'overtime_amount' => Money::round($overtime),
            'salary_amount' => Money::round($regular + $overtime),
        ];
    }

    /**
     * Rates summary for the UI.
     */
    public function rates(User $user): array
    {
        $settings = $this->settings($user);
        $standardDays = (int) round($this->workingDaysPerMonth($user) / $this->periodsPerMonth($settings));

        return [
            'configured' => $settings->isConfigured(),
            'salary_type' => $settings->salary_type,
            'basic_per_period' => $this->basicForPeriod($user, $standardDays, $settings),
            'monthly_equivalent' => Money::round($this->monthlyEquivalent($user, $settings)),
            'daily_rate' => Money::round($this->dailyRate($user, $settings)),
            'hourly_rate' => Money::round($this->hourlyRate($user, $settings)),
            'overtime_hourly_rate' => Money::round($this->overtimeHourlyRate($user, $settings)),
            'expected_hours_per_day' => (float) $settings->expected_hours_per_day,
            'overtime_enabled' => (bool) $settings->overtime_enabled,
            'overtime_threshold_minutes' => (int) ($settings->overtime_threshold_minutes ?? 60),
        ];
    }
}
