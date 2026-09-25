<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTrackerUser;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use CreatesTrackerUser, RefreshDatabase;

    public function test_dashboard_shows_today_state_and_notification_plan(): void
    {
        $this->actingAsTracker();
        $this->travelTo($this->manila('2026-09-25 06:30')); // Friday before work

        $response = $this->getJson('/api/dashboard')->assertOk();
        $response->assertJsonPath('data.today.date', '2026-09-25')
            ->assertJsonPath('data.today.day_name', 'Friday')
            ->assertJsonPath('data.today.state', 'SCHEDULED')
            ->assertJsonPath('data.today.is_working_day', true)
            ->assertJsonPath('data.today.schedule.start_label', '8:00 AM')
            ->assertJsonPath('data.today.schedule.end_label', '5:00 PM')
            ->assertJsonPath('data.period.period.start_date', '2026-09-11')
            ->assertJsonPath('data.period.period.end_date', '2026-09-25')
            ->assertJsonPath('data.salary.configured', true)
            ->assertJsonPath('data.notification.enabled', true)
            ->assertJsonPath('data.notification.current.state', 'SCHEDULED')
            ->assertJsonPath('data.notification.current.scheduled_start', '2026-09-25T08:00:00+08:00')
            ->assertJsonPath('data.notification.current.show_at', '2026-09-25T07:00:00+08:00');

        $upcoming = collect($response->json('data.notification.upcoming'));
        $this->assertSame('2026-09-26', $upcoming->first()['date'], 'Saturday is a working day');
        $this->assertFalse($upcoming->contains('date', '2026-09-27'), 'Sunday has no work notification');
        $this->assertTrue($upcoming->contains('date', '2026-09-28'));
    }

    public function test_dashboard_on_rest_day_and_no_earnings_until_time_out(): void
    {
        $this->actingAsTracker();
        $this->travelTo($this->manila('2026-09-27 09:00')); // Sunday
        $this->getJson('/api/dashboard')->assertOk()
            ->assertJsonPath('data.today.state', 'REST_DAY')
            ->assertJsonPath('data.today.is_working_day', false);

        $this->travelTo($this->manila('2026-09-28 08:00')); // Monday
        $this->postJson('/api/attendance/time-in')->assertOk();
        $this->travelTo($this->manila('2026-09-28 12:00'));

        $this->getJson('/api/dashboard')->assertOk()
            ->assertJsonPath('data.today.state', 'ON_DUTY')
            ->assertJsonPath('data.today.is_live', true)
            ->assertJsonPath('data.today.worked_minutes', 240) // 8:00-12:00, lunch (12:00-1:00) not reached yet
            ->assertJsonPath('data.today.earned', null)
            ->assertJsonPath('data.period.summary.salary_earned', 0);

        $this->travelTo($this->manila('2026-09-28 17:00'));
        $this->postJson('/api/attendance/time-out')->assertOk();

        $this->getJson('/api/dashboard')->assertOk()
            ->assertJsonPath('data.today.state', 'COMPLETED')
            ->assertJsonPath('data.today.earned', 769.23)
            ->assertJsonPath('data.period.summary.salary_earned', 769.23);
    }

    public function test_work_schedule_can_be_changed_and_drives_notifications(): void
    {
        $this->actingAsTracker();
        $days = [];
        foreach (range(0, 6) as $d) {
            $working = in_array($d, [1, 2, 3, 4, 5], true);
            $days[] = ['day_of_week' => $d, 'is_working_day' => $working, 'start_time' => $working ? '09:00' : null, 'end_time' => $working ? '18:00' : null, 'break_minutes' => 60];
        }
        $this->putJson('/api/work-schedule', ['days' => $days])->assertOk()->assertJsonPath('data.6.is_working_day', false);

        $this->travelTo($this->manila('2026-09-26 07:00')); // Saturday now a rest day
        $this->getJson('/api/notifications/plan')->assertOk()
            ->assertJsonPath('data.current.state', 'REST_DAY')
            ->assertJsonPath('data.upcoming.0.date', '2026-09-28')
            ->assertJsonPath('data.upcoming.0.scheduled_start', '2026-09-28T09:00:00+08:00');
    }

    public function test_statistics_endpoint(): void
    {
        $this->actingAsTracker();
        $this->travelTo($this->manila('2026-09-22 12:00'));
        $this->postJson('/api/attendance/manual', ['work_date' => '2026-09-21', 'time_in' => '08:10', 'time_out' => '18:00'])->assertCreated();
        $this->postJson('/api/expenses', ['amount' => 80, 'expense_date' => '2026-09-21'])->assertCreated();

        $stats = $this->getJson('/api/statistics?range=custom&from=2026-09-21&to=2026-09-22')->assertOk()->json('data');
        $this->assertSame(1, $stats['summary']['days_worked']);
        $this->assertSame(1, $stats['summary']['days_late']);
        $this->assertSame(530, $stats['summary']['worked_minutes']);
        $this->assertSame(50, $stats['summary']['overtime_minutes']);
        $this->assertSame(530, $stats['summary']['average_minutes_per_day']);
        $this->assertCount(2, $stats['series']);
        $this->assertSame(80.0, (float) $stats['series'][0]['expenses']);
        $this->assertSame('Uncategorized', $stats['expenses_by_category'][0]['name']);
    }
}
