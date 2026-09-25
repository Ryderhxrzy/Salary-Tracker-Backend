<?php

namespace Tests\Feature;

use App\Services\SalaryPeriodService;
use App\Services\SalaryService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTrackerUser;
use Tests\TestCase;

class SalaryTest extends TestCase
{
    use CreatesTrackerUser, RefreshDatabase;

    public function test_semi_monthly_cutoffs_11_25_and_26_10(): void
    {
        $user = $this->trackerUser();
        $service = app(SalaryPeriodService::class);
        $settings = $user->salarySetting;

        $cases = [
            '2026-08-11' => ['2026-08-11', '2026-08-25'],
            '2026-08-20' => ['2026-08-11', '2026-08-25'],
            '2026-08-25' => ['2026-08-11', '2026-08-25'],
            '2026-08-26' => ['2026-08-26', '2026-09-10'],
            '2026-09-05' => ['2026-08-26', '2026-09-10'],
            '2026-09-10' => ['2026-08-26', '2026-09-10'],
            '2026-09-25' => ['2026-09-11', '2026-09-25'],
            '2026-09-26' => ['2026-09-26', '2026-10-10'],
            '2026-12-31' => ['2026-12-26', '2027-01-10'],
            '2027-01-03' => ['2026-12-26', '2027-01-10'],
        ];

        foreach ($cases as $date => [$start, $end]) {
            $bounds = $service->boundsFor($settings, CarbonImmutable::parse($date, 'Asia/Manila'));
            $this->assertSame($start, $bounds['start']->toDateString(), "start for {$date}");
            $this->assertSame($end, $bounds['end']->toDateString(), "end for {$date}");
        }
    }

    public function test_semi_monthly_pay_dates(): void
    {
        $user = $this->trackerUser();
        $service = app(SalaryPeriodService::class);
        $settings = $user->salarySetting;

        $cases = [
            '2026-08-25' => '2026-08-30', // Aug 11-25 paid Aug 30
            '2026-09-10' => '2026-09-15', // Aug 26-Sep 10 paid Sep 15
            '2027-02-25' => '2027-02-28', // Feb 11-25 paid at month end
        ];

        foreach ($cases as $end => $payDate) {
            $this->assertSame($payDate, $service->payDateFor($settings, CarbonImmutable::parse($end, 'Asia/Manila'))->toDateString(), "pay date for cut-off ending {$end}");
        }
    }

    public function test_dashboard_shows_cutoff_pay_date_expected_salary_and_previous_payday(): void
    {
        $this->actingAsTracker();
        // Thursday Aug 27: current cut-off Aug 26 - Sep 10, previous Aug 11 - 25 is paid Aug 30.
        $this->travelTo($this->manila('2026-08-27 07:00'));

        $response = $this->getJson('/api/dashboard')->assertOk()
            ->assertJsonPath('data.period.period.start_date', '2026-08-26')
            ->assertJsonPath('data.period.period.end_date', '2026-09-10')
            ->assertJsonPath('data.period.period.pay_date', '2026-09-15')
            // Mon-Sat from Aug 26 to Sep 10 = 14 working days, all still ahead except Aug 26.
            ->assertJsonPath('data.period.summary.working_days', 14)
            ->assertJsonPath('data.period.summary.remaining_days', 13)
            ->assertJsonPath('data.payday.period.start_date', '2026-08-11')
            ->assertJsonPath('data.payday.period.pay_date', '2026-08-30');

        $this->assertEqualsWithDelta(13 * 769.23, $response->json('data.period.summary.expected_salary'), 0.01);

        // After Aug 30 the previous cut-off leaves the dashboard.
        $this->travelTo($this->manila('2026-08-31 07:00'));
        $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('data.payday', null);
    }

    public function test_monthly_weekly_and_biweekly_bounds(): void
    {
        $user = $this->trackerUser(true, ['period_type' => 'monthly', 'period_start_day' => 1]);
        $service = app(SalaryPeriodService::class);
        $date = CarbonImmutable::parse('2026-09-25', 'Asia/Manila');

        $bounds = $service->boundsFor($user->salarySetting, $date);
        $this->assertSame(['2026-09-01', '2026-09-30'], [$bounds['start']->toDateString(), $bounds['end']->toDateString()]);

        $user->salarySetting->fill(['period_type' => 'weekly', 'period_start_weekday' => 1])->save();
        $bounds = $service->boundsFor($user->salarySetting->fresh(), $date);
        $this->assertSame(['2026-09-21', '2026-09-27'], [$bounds['start']->toDateString(), $bounds['end']->toDateString()]);

        $user->salarySetting->fill(['period_type' => 'biweekly', 'period_anchor_date' => '2026-09-14'])->save();
        $bounds = $service->boundsFor($user->salarySetting->fresh(), $date);
        $this->assertSame(['2026-09-14', '2026-09-27'], [$bounds['start']->toDateString(), $bounds['end']->toDateString()]);
    }

    public function test_rate_resolution_for_each_salary_type(): void
    {
        $salary = app(SalaryService::class);

        $daily = $this->trackerUser();
        $this->assertEqualsWithDelta(769.23, $salary->dailyRate($daily), 0.001);
        $this->assertEqualsWithDelta(96.15, $salary->hourlyRate($daily), 0.01);
        $this->assertEqualsWithDelta(120.19, $salary->overtimeHourlyRate($daily), 0.001);

        $hourly = $this->trackerUser(true, ['salary_type' => 'hourly', 'hourly_rate' => 100, 'overtime_hourly_rate' => null, 'overtime_multiplier' => 1.25]);
        $this->assertEqualsWithDelta(800, $salary->dailyRate($hourly), 0.001);
        $this->assertEqualsWithDelta(125, $salary->overtimeHourlyRate($hourly), 0.001);

        // 6 working days/week: weekly 6000 => 1000/day; monthly 26000 => 26000 / 26 = 1000/day
        $weekly = $this->trackerUser(true, ['salary_type' => 'weekly', 'weekly_rate' => 6000]);
        $this->assertEqualsWithDelta(1000, $salary->dailyRate($weekly), 0.001);

        $monthly = $this->trackerUser(true, ['salary_type' => 'monthly', 'monthly_rate' => 26000]);
        $this->assertEqualsWithDelta(1000, $salary->dailyRate($monthly), 0.001);

        $none = $this->trackerUser(false);
        $this->assertNull($salary->dailyRate($none));
        $this->assertFalse($salary->rates($none)['configured']);
    }

    public function test_hourly_salary_pays_per_worked_hour(): void
    {
        $this->actingAsTracker(true, ['salary_type' => 'hourly', 'hourly_rate' => 100, 'overtime_hourly_rate' => null]);
        $this->travelTo($this->manila('2026-09-25 08:00'));
        $this->postJson('/api/attendance/time-in')->assertOk();
        $this->travelTo($this->manila('2026-09-25 13:30')); // 5.5h - 1h break = 4.5h

        $response = $this->postJson('/api/attendance/time-out')
            ->assertOk()
            ->assertJsonPath('data.attendance.worked_minutes', 270);
        $this->assertEqualsWithDelta(450.0, $response->json('data.attendance.salary_amount'), 0.001);
    }

    public function test_period_summary_connects_salary_income_and_expenses(): void
    {
        $user = $this->actingAsTracker();

        foreach (['2026-09-14', '2026-09-15'] as $date) {
            $this->postJson('/api/attendance/manual', ['work_date' => $date, 'time_in' => '08:00', 'time_out' => '17:00'])->assertCreated();
        }
        $this->postJson('/api/attendance/manual', ['work_date' => '2026-09-16', 'time_in' => '08:00', 'time_out' => '19:00'])->assertCreated();
        $this->postJson('/api/attendance/manual', ['work_date' => '2026-09-17', 'status' => 'absent'])->assertCreated();

        $category = $user->expenseCategories()->where('name', 'Food')->first();
        $this->postJson('/api/expenses', ['amount' => 150.50, 'expense_date' => '2026-09-15', 'expense_category_id' => $category->id, 'description' => 'Lunch'])->assertCreated();
        $this->postJson('/api/expenses', ['amount' => 300, 'expense_date' => '2026-09-20', 'payment_method' => 'gcash'])->assertCreated();
        $this->postJson('/api/salary-adjustments', ['type' => 'bonus', 'amount' => 1000, 'adjustment_date' => '2026-09-20'])->assertCreated();
        $this->postJson('/api/salary-adjustments', ['type' => 'deduction', 'amount' => 200, 'adjustment_date' => '2026-09-21', 'description' => 'SSS'])->assertCreated();

        $this->travelTo($this->manila('2026-09-22 12:00'));
        $response = $this->getJson('/api/salary/summary?range=period')->assertOk();
        $summary = $response->json('data.summary');

        $this->assertSame('2026-09-11', $summary['from']);
        $this->assertSame('2026-09-25', $summary['to']);
        $this->assertSame(3, $summary['days_worked']);
        $this->assertSame(1, $summary['days_absent']);
        $this->assertSame(120, $summary['overtime_minutes']);
        // 3 x 769.23 = 2307.69 regular + 2h x 120.19 = 240.38 overtime
        $this->assertEqualsWithDelta(2307.69, $summary['regular_pay'], 0.001);
        $this->assertEqualsWithDelta(240.38, $summary['overtime_pay'], 0.001);
        $this->assertEqualsWithDelta(2548.07, $summary['salary_earned'], 0.001);
        $this->assertEqualsWithDelta(1000, $summary['additional_income'], 0.001);
        $this->assertEqualsWithDelta(200, $summary['deductions'], 0.001);
        $this->assertEqualsWithDelta(450.50, $summary['expenses'], 0.001);
        // 2548.07 + 1000 - 200 - 450.50
        $this->assertEqualsWithDelta(2897.57, $summary['remaining'], 0.001);
        $this->assertEqualsWithDelta(0, $summary['absence_deduction'], 0.001, 'absences are not deducted unless configured');
    }

    public function test_absence_deduction_only_when_enabled(): void
    {
        $this->actingAsTracker(true, ['deduct_absences' => true]);
        $this->postJson('/api/attendance/manual', ['work_date' => '2026-09-17', 'status' => 'absent'])->assertCreated();
        $this->travelTo($this->manila('2026-09-22 12:00'));

        $summary = $this->getJson('/api/salary/summary?range=period')->assertOk()->json('data.summary');
        $this->assertEqualsWithDelta(769.23, $summary['absence_deduction'], 0.001);
        $this->assertEqualsWithDelta(-769.23, $summary['remaining'], 0.001);
    }

    public function test_salary_not_configured_is_reported_honestly(): void
    {
        $this->actingAsTracker(withSalary: false);
        $this->travelTo($this->manila('2026-09-22 12:00'));

        $this->getJson('/api/salary')->assertOk()
            ->assertJsonPath('data.settings.configured', false)
            ->assertJsonPath('data.summary.salary_configured', false)
            ->assertJsonPath('data.summary.salary_earned', null);

        $this->getJson('/api/dashboard')->assertOk()
            ->assertJsonPath('data.salary.configured', false)
            ->assertJsonPath('data.today.earned', null);
    }

    public function test_updating_salary_settings_recalculates_recent_attendance(): void
    {
        $this->actingAsTracker();
        $this->travelTo($this->manila('2026-09-22 12:00'));
        $this->postJson('/api/attendance/manual', ['work_date' => '2026-09-21', 'time_in' => '08:00', 'time_out' => '17:00'])
            ->assertCreated()->assertJsonPath('data.salary_amount', 769.23);

        $this->putJson('/api/salary-settings', ['salary_type' => 'daily', 'daily_rate' => 800])
            ->assertOk()
            ->assertJsonPath('data.settings.daily_rate', 800);

        $this->assertDatabaseHas('attendance_records', ['work_date' => '2026-09-21', 'salary_amount' => 800.00]);

        $this->putJson('/api/salary-settings', ['salary_type' => 'monthly'])
            ->assertStatus(422)
            ->assertJsonPath('errors.monthly_rate.0', 'Please enter your monthly salary rate.');
    }

    public function test_salary_periods_list_includes_summaries(): void
    {
        $this->actingAsTracker();
        $this->travelTo($this->manila('2026-09-22 12:00'));

        $response = $this->getJson('/api/salary/periods?count=3')->assertOk();
        $periods = $response->json('data');
        $this->assertCount(3, $periods);
        $this->assertSame('2026-09-11', $periods[0]['start_date']);
        $this->assertSame('2026-08-26', $periods[1]['start_date']);
        $this->assertSame('2026-08-11', $periods[2]['start_date']);
        $this->assertArrayHasKey('remaining', $periods[0]['summary']);
    }
}
