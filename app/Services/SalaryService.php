<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\SalarySetting;
use App\Models\User;
use App\Support\Money;

/**
 * Rate resolution and pay computation. Never invents a salary: every method
 * returns null when the user has not configured one.
 */
class SalaryService
{
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
     * Effective hourly rate derived from whichever salary type is configured.
     */
    public function hourlyRate(User $user, ?SalarySetting $settings = null): ?float
    {
        $settings ??= $this->settings($user);
        if (! $settings->isConfigured()) {
            return null;
        }

        $hours = max(0.01, (float) $settings->expected_hours_per_day);
        if ($settings->salary_type === 'hourly') {
            return (float) $settings->hourly_rate;
        }

        $daily = $this->dailyRate($user, $settings);

        return $daily === null ? null : $daily / $hours;
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

        $wdpw = $this->schedules->workingDaysPerWeek($user);
        $hours = max(0.01, (float) $settings->expected_hours_per_day);

        return match ($settings->salary_type) {
            'daily' => (float) $settings->daily_rate,
            'hourly' => (float) $settings->hourly_rate * $hours,
            'weekly' => (float) $settings->weekly_rate / $wdpw,
            'biweekly' => (float) $settings->biweekly_rate / ($wdpw * 2),
            'monthly' => (float) $settings->monthly_rate / ($wdpw * 52 / 12),
            default => null,
        };
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

        // Only days actually worked earn pay (leave/absent/rest are handled by summaries).
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

        return [
            'configured' => $settings->isConfigured(),
            'salary_type' => $settings->salary_type,
            'daily_rate' => Money::round($this->dailyRate($user, $settings)),
            'hourly_rate' => Money::round($this->hourlyRate($user, $settings)),
            'overtime_hourly_rate' => Money::round($this->overtimeHourlyRate($user, $settings)),
            'expected_hours_per_day' => (float) $settings->expected_hours_per_day,
            'overtime_enabled' => (bool) $settings->overtime_enabled,
        ];
    }
}
