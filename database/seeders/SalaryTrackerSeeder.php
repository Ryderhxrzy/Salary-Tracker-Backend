<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\ExpenseService;
use App\Services\NotificationService;
use App\Services\SalaryService;
use App\Services\WorkScheduleService;
use Illuminate\Database\Seeder;

/**
 * Creates the owner's account with the salary + schedule they use:
 *  - Monday to Saturday, 8:00 AM - 5:00 PM, 1 hour break, Sunday rest day
 *  - Daily rate ₱769.23, overtime ₱120.19 / hour
 *  - Salary cut-offs: 11th-25th and 26th-10th
 *
 * Override the credentials with SEED_USER_EMAIL / SEED_USER_PASSWORD in .env.
 */
class SalaryTrackerSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('SEED_USER_EMAIL', 'me@salarytracker.local');
        $password = env('SEED_USER_PASSWORD', 'password123');
        $name = env('SEED_USER_NAME', 'Me');

        $user = User::firstOrCreate(['email' => $email], ['name' => $name, 'password' => $password]);

        $user->profile()->firstOrCreate([], ['full_name' => $name, 'nickname' => $name, 'timezone' => 'Asia/Manila']);

        app(SalaryService::class)->settings($user)->fill([
            'salary_type' => 'daily',
            'daily_rate' => 769.23,
            'expected_hours_per_day' => 8,
            'overtime_enabled' => true,
            'overtime_multiplier' => 1.25,
            'overtime_hourly_rate' => 120.19,
            // Cut-offs: 11th - 25th and 26th - 10th of the next month
            'period_type' => 'semi_monthly',
            'period_start_day' => 11,
            'period_second_day' => 26,
        ])->save();

        app(NotificationService::class)->settings($user);
        app(ExpenseService::class)->ensureDefaultCategories($user);

        $days = [];
        foreach (range(0, 6) as $day) {
            $working = $day !== 0;
            $days[] = [
                'day_of_week' => $day,
                'is_working_day' => $working,
                'start_time' => $working ? '08:00' : null,
                'end_time' => $working ? '17:00' : null,
                'break_minutes' => $working ? 60 : 0,
            ];
        }
        app(WorkScheduleService::class)->update($user, $days);

        $this->command?->info("Seeded account {$email} (password: {$password}).");
    }
}
