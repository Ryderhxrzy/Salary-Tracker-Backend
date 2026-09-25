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
            // Mon-Sat from Aug 26 to Sep 10 = 14 working days; Aug 26 had no record => absent.
            ->assertJsonPath('data.period.summary.working_days', 14)
            ->assertJsonPath('data.period.summary.days_remaining', 13)
            ->assertJsonPath('data.period.summary.days_absent', 1)
            ->assertJsonPath('data.payday.period.start_date', '2026-08-11')
            ->assertJsonPath('data.payday.period.pay_date', '2026-08-30')
            ->assertJsonPath('data.payday.summary.status', 'completed')
            ->assertJsonPath('data.next_payday.date', '2026-08-30')
            ->assertJsonPath('data.next_payday.days_until', 3);

        // Daily type: basic = 14 days × 769.23, minus the absent day.
        $this->assertEqualsWithDelta(13 * 769.23, $response->json('data.period.summary.salary'), 0.01);

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
        $this->assertEqualsWithDelta(769.23, $summary['absence_deduction'], 0.001);
    }

    public function test_basic_salary_per_period_derives_daily_and_hourly_rates(): void
    {
        // ₱10,000 every semi-monthly cut-off = ₱20,000 a month over 26 working days (Mon-Sat).
        $user = $this->trackerUser(true, ['salary_type' => 'per_period', 'basic_salary' => 10000]);
        $rates = app(SalaryService::class)->rates($user);

        $this->assertTrue($rates['configured']);
        $this->assertEqualsWithDelta(10000, $rates['basic_per_period'], 0.001);
        $this->assertEqualsWithDelta(20000, $rates['monthly_equivalent'], 0.001);
        $this->assertEqualsWithDelta(769.23, $rates['daily_rate'], 0.001);
        $this->assertEqualsWithDelta(96.15, $rates['hourly_rate'], 0.001);
        $this->assertEqualsWithDelta(120.19, $rates['overtime_hourly_rate'], 0.001);
    }

    public function test_period_salary_is_basic_minus_absences_and_undertime_plus_overtime(): void
    {
        $this->actingAsTracker(true, ['salary_type' => 'per_period', 'basic_salary' => 10000]);
        $days = [
            ['2026-09-11', '07:44', '17:01'], ['2026-09-12', '07:45', '17:01'],
            ['2026-09-16', '07:45', '17:03'], ['2026-09-17', '07:35', '22:08'],
            ['2026-09-18', '07:43', '12:01'], ['2026-09-19', '07:53', '22:03'],
            ['2026-09-21', '07:47', '23:11'], ['2026-09-22', '07:44', '22:07'],
            ['2026-09-23', '07:48', '22:12'], ['2026-09-24', '07:54', '22:01'],
        ];
        foreach ($days as [$date, $in, $out]) {
            $this->postJson('/api/attendance/manual', ['work_date' => $date, 'time_in' => $in, 'time_out' => $out])->assertCreated();
        }
        $this->postJson('/api/attendance/manual', ['work_date' => '2026-09-14', 'status' => 'absent'])->assertCreated();
        $this->postJson('/api/attendance/manual', ['work_date' => '2026-09-15', 'status' => 'absent'])->assertCreated();

        // Sep 25: timed in at 7:45, still on duty at 10:00 => counted as a full day until time out.
        $this->travelTo($this->manila('2026-09-25 07:45'));
        $this->postJson('/api/attendance/time-in')->assertOk();
        $this->travelTo($this->manila('2026-09-25 10:00'));

        $s = $this->getJson('/api/salary')->assertOk()->json('data.summary');

        $this->assertSame('ongoing', $s['status']);
        $this->assertSame('2026-09-30', $s['pay_date']);
        $this->assertSame(5, $s['days_until_pay']);
        $this->assertSame(13, $s['working_days']);
        $this->assertSame(12, $s['days_done']);
        $this->assertSame(1, $s['days_remaining']);
        $this->assertSame(11, $s['days_worked']);
        $this->assertSame(2, $s['days_absent']);
        $this->assertSame(240, $s['undertime_minutes'], 'half day on Sep 18');
        $this->assertSame(1902, $s['overtime_minutes']);
        $this->assertEqualsWithDelta(10000, $s['basic_salary'], 0.001);
        $this->assertEqualsWithDelta(1538.46, $s['absence_deduction'], 0.01);
        $this->assertEqualsWithDelta(384.61, $s['undertime_deduction'], 0.02);
        $this->assertEqualsWithDelta(3810.02, $s['overtime_pay'], 0.01);
        // 10,000 - 1,538.46 - 384.61 + 3,810.02
        $this->assertEqualsWithDelta(11886.95, $s['salary'], 0.02);
        $this->assertEqualsWithDelta(11117.71, $s['earned_to_date'], 0.01);
    }

    public function test_past_working_days_without_records_count_as_absent(): void
    {
        $this->actingAsTracker(true, ['salary_type' => 'per_period', 'basic_salary' => 10000]);
        foreach (['2026-09-11', '2026-09-12'] as $date) {
            $this->postJson('/api/attendance/manual', ['work_date' => $date, 'time_in' => '08:00', 'time_out' => '17:00'])->assertCreated();
        }
        // Wednesday 9 AM: Mon 14 and Tue 15 have no record => absent; today is not absent (yet).
        $this->travelTo($this->manila('2026-09-16 09:00'));

        $s = $this->getJson('/api/dashboard')->assertOk()->json('data.period.summary');
        $this->assertSame(2, $s['days_absent']);
        $this->assertSame(2, $s['days_worked']);
        $this->assertSame(9, $s['days_remaining']);
        $this->assertEqualsWithDelta(1538.46, $s['absence_deduction'], 0.01);
        $this->assertEqualsWithDelta(8461.54, $s['salary'], 0.01);
    }

    public function test_leave_records_affect_the_period_salary(): void
    {
        $this->actingAsTracker(true, ['salary_type' => 'per_period', 'basic_salary' => 10000]);
        $this->postJson('/api/leave-records', ['leave_date' => '2026-09-14', 'type' => 'sick_leave', 'is_paid' => true])->assertCreated();
        $this->postJson('/api/leave-records', ['leave_date' => '2026-09-15', 'type' => 'vacation', 'is_paid' => false])->assertCreated();
        $this->postJson('/api/leave-records', ['leave_date' => '2026-09-16', 'type' => 'holiday'])->assertCreated();
        $this->travelTo($this->manila('2026-09-17 07:00'));

        $s = $this->getJson('/api/dashboard')->assertOk()->json('data.period.summary');
        $this->assertSame(12, $s['working_days'], 'a holiday is not a working day');
        $this->assertSame(1, $s['days_leave']);
        $this->assertSame(1, $s['days_unpaid_leave']);
        // Sep 11 (Fri) and Sep 12 (Sat) had no record => absent, plus one unpaid leave day.
        $this->assertSame(2, $s['days_absent']);
        $this->assertEqualsWithDelta(3 * 769.23, $s['absence_deduction'], 0.02);
        $this->assertEqualsWithDelta(10000 - 3 * 769.23, $s['salary'], 0.02);
    }

    public function test_period_moves_from_ongoing_to_completed_to_paid_and_stays_in_history(): void
    {
        $this->actingAsTracker(true, ['salary_type' => 'per_period', 'basic_salary' => 10000]);
        $workingDays = ['2026-09-11', '2026-09-12', '2026-09-14', '2026-09-15', '2026-09-16', '2026-09-17', '2026-09-18', '2026-09-19', '2026-09-21', '2026-09-22', '2026-09-23', '2026-09-24', '2026-09-25'];
        foreach ($workingDays as $date) {
            $this->postJson('/api/attendance/manual', ['work_date' => $date, 'time_in' => '08:00', 'time_out' => '17:00'])->assertCreated();
        }

        // Still inside the cut-off.
        $this->travelTo($this->manila('2026-09-20 12:00'));
        $this->getJson('/api/dashboard')->assertOk()
            ->assertJsonPath('data.period.summary.status', 'ongoing')
            ->assertJsonPath('data.period.summary.salary', 10000)
            ->assertJsonPath('data.payday', null)
            ->assertJsonPath('data.next_payday.kind', 'current')
            ->assertJsonPath('data.next_payday.date', '2026-09-30');

        // Cut-off ended, payday (Sep 30) not reached: the new period is current, the old one waits for payday.
        $this->travelTo($this->manila('2026-09-27 12:00')); // Sunday
        $dash = $this->getJson('/api/dashboard')->assertOk()
            ->assertJsonPath('data.period.period.start_date', '2026-09-26')
            ->assertJsonPath('data.period.period.end_date', '2026-10-10')
            ->assertJsonPath('data.period.summary.status', 'ongoing')
            ->assertJsonPath('data.payday.period.start_date', '2026-09-11')
            ->assertJsonPath('data.payday.summary.status', 'completed')
            ->assertJsonPath('data.payday.summary.salary', 10000)
            ->assertJsonPath('data.next_payday.kind', 'completed')
            ->assertJsonPath('data.next_payday.date', '2026-09-30')
            ->assertJsonPath('data.next_payday.days_until', 3)
            ->json('data');
        // The new period has its own expected salary: Sep 26 (Sat) had no record => already absent.
        $this->assertSame(1, $dash['period']['summary']['days_absent']);
        $this->assertEqualsWithDelta(10000 - 769.23, $dash['period']['summary']['salary'], 0.02);

        // After payday the old cut-off leaves the dashboard but stays in the history as paid.
        $this->travelTo($this->manila('2026-10-01 12:00'));
        $this->getJson('/api/dashboard')->assertOk()
            ->assertJsonPath('data.payday', null)
            ->assertJsonPath('data.next_payday.kind', 'current')
            ->assertJsonPath('data.next_payday.date', '2026-10-15');

        $periods = $this->getJson('/api/salary/periods?count=3')->assertOk()->json('data');
        $this->assertSame(['2026-09-26', '2026-09-11', '2026-08-26'], array_column($periods, 'start_date'));
        $this->assertSame('ongoing', $periods[0]['status']);
        $this->assertSame('paid', $periods[1]['status']);
        $this->assertSame('2026-09-30', $periods[1]['pay_date']);
        $this->assertEqualsWithDelta(10000, $periods[1]['summary']['basic_salary'], 0.001);
        $this->assertEqualsWithDelta(10000, $periods[1]['summary']['salary'], 0.001);
        $this->assertSame(0, $periods[1]['summary']['days_absent']);
    }

    public function test_salary_not_configured_is_reported_honestly(): void
    {
        $this->actingAsTracker(withSalary: false);
        $this->travelTo($this->manila('2026-09-22 12:00'));

        $this->getJson('/api/salary')->assertOk()
            ->assertJsonPath('data.settings.configured', false)
            ->assertJsonPath('data.summary.salary_configured', false)
            ->assertJsonPath('data.summary.salary', null);

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
