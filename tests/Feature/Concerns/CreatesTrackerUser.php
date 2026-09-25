<?php

namespace Tests\Feature\Concerns;

use App\Models\User;
use App\Services\ExpenseService;
use App\Services\SalaryService;
use App\Services\WorkScheduleService;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;

trait CreatesTrackerUser
{
    /**
     * A user in Asia/Manila working Mon-Sat 08:00-17:00 (1h break) with an
     * optional daily salary of ₱769.23 and overtime at ₱120.19/hour.
     */
    protected function trackerUser(bool $withSalary = true, array $salaryOverrides = []): User
    {
        $user = User::factory()->create();
        $user->profile()->create(['full_name' => $user->name, 'timezone' => 'Asia/Manila', 'late_grace_minutes' => 0]);
        app(WorkScheduleService::class)->ensureDefaults($user);
        app(ExpenseService::class)->ensureDefaultCategories($user);

        $settings = app(SalaryService::class)->settings($user);
        if ($withSalary) {
            $settings->fill(array_merge([
                'salary_type' => 'daily',
                'daily_rate' => 769.23,
                'expected_hours_per_day' => 8,
                'overtime_enabled' => true,
                'overtime_multiplier' => 1.25,
                'overtime_hourly_rate' => 120.19,
                'period_type' => 'semi_monthly',
                'period_start_day' => 11,
                'period_second_day' => 26,
            ], $salaryOverrides))->save();
        }

        return $user->fresh(['profile', 'salarySetting']);
    }

    protected function actingAsTracker(bool $withSalary = true, array $salaryOverrides = []): User
    {
        $user = $this->trackerUser($withSalary, $salaryOverrides);
        Sanctum::actingAs($user);

        return $user;
    }

    /** Manila local time -> UTC instant for travelTo(). */
    protected function manila(string $dateTime): CarbonImmutable
    {
        return CarbonImmutable::parse($dateTime, 'Asia/Manila')->utc();
    }
}
