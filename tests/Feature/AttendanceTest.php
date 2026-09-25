<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTrackerUser;
use Tests\TestCase;

class AttendanceTest extends TestCase
{
    use CreatesTrackerUser, RefreshDatabase;

    public function test_time_in_records_attendance_and_lateness(): void
    {
        $user = $this->actingAsTracker();
        $this->travelTo($this->manila('2026-09-25 08:03')); // Friday

        $response = $this->postJson('/api/attendance/time-in', ['source' => 'notification']);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.state', 'ON_DUTY')
            ->assertJsonPath('data.attendance.work_date', '2026-09-25')
            ->assertJsonPath('data.attendance.time_in_label', '8:03 AM')
            ->assertJsonPath('data.attendance.status', 'late')
            ->assertJsonPath('data.attendance.late_minutes', 3)
            ->assertJsonPath('data.attendance.source', 'notification')
            ->assertJsonPath('data.notification.current.state', 'ON_DUTY');

        $this->assertDatabaseHas('attendance_records', ['user_id' => $user->id, 'work_date' => '2026-09-25']);
    }

    public function test_time_in_before_schedule_is_present(): void
    {
        $this->actingAsTracker();
        $this->travelTo($this->manila('2026-09-25 07:55'));

        $this->postJson('/api/attendance/time-in')
            ->assertOk()
            ->assertJsonPath('data.attendance.status', 'present')
            ->assertJsonPath('data.attendance.late_minutes', 0);
    }

    public function test_duplicate_time_in_is_rejected(): void
    {
        $this->actingAsTracker();
        $this->travelTo($this->manila('2026-09-25 08:00'));

        $this->postJson('/api/attendance/time-in')->assertOk();
        $this->postJson('/api/attendance/time-in')
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'ALREADY_ON_DUTY');

        $this->assertSame(1, AttendanceRecord::count());
    }

    public function test_time_in_after_completing_the_day_is_rejected(): void
    {
        $this->actingAsTracker();
        $this->travelTo($this->manila('2026-09-25 08:00'));
        $this->postJson('/api/attendance/time-in')->assertOk();
        $this->travelTo($this->manila('2026-09-25 17:00'));
        $this->postJson('/api/attendance/time-out')->assertOk();

        $this->travelTo($this->manila('2026-09-25 18:00'));
        $this->postJson('/api/attendance/time-in')
            ->assertStatus(409)
            ->assertJsonPath('code', 'DUPLICATE_TIME_IN');
    }

    public function test_time_out_without_time_in_is_rejected(): void
    {
        $this->actingAsTracker();
        $this->travelTo($this->manila('2026-09-25 17:00'));

        $this->postJson('/api/attendance/time-out')
            ->assertStatus(409)
            ->assertJsonPath('code', 'NO_ACTIVE_TIME_IN');
    }

    public function test_duplicate_time_out_is_rejected(): void
    {
        $this->actingAsTracker();
        $this->travelTo($this->manila('2026-09-25 08:00'));
        $this->postJson('/api/attendance/time-in')->assertOk();
        $this->travelTo($this->manila('2026-09-25 17:00'));
        $this->postJson('/api/attendance/time-out')->assertOk();
        $this->postJson('/api/attendance/time-out')
            ->assertStatus(409)
            ->assertJsonPath('code', 'DUPLICATE_TIME_OUT');
    }

    public function test_time_out_calculates_hours_overtime_and_salary(): void
    {
        $this->actingAsTracker();
        $this->travelTo($this->manila('2026-09-25 08:00'));
        $this->postJson('/api/attendance/time-in')->assertOk();

        // 08:00 -> 19:00 = 11h, minus 1h break = 10h worked: 8h regular + 2h overtime
        $this->travelTo($this->manila('2026-09-25 19:00'));
        $response = $this->postJson('/api/attendance/time-out');

        $response->assertOk()
            ->assertJsonPath('data.state', 'COMPLETED')
            ->assertJsonPath('data.attendance.worked_minutes', 600)
            ->assertJsonPath('data.attendance.regular_minutes', 480)
            ->assertJsonPath('data.attendance.overtime_minutes', 120)
            ->assertJsonPath('data.attendance.status', 'present')
            ->assertJsonPath('data.attendance.regular_amount', 769.23)
            ->assertJsonPath('data.attendance.overtime_amount', 240.38)
            ->assertJsonPath('data.attendance.salary_amount', 1009.61)
            ->assertJsonPath('data.notification.current.state', 'COMPLETED');
    }

    public function test_half_day_is_paid_per_hour_worked(): void
    {
        $this->actingAsTracker();
        $this->travelTo($this->manila('2026-09-25 14:00'));
        $this->postJson('/api/attendance/time-in')->assertOk();

        // 2:00 PM -> 5:00 PM = 3h, lunch already over: 769.23 / 8h x 3h
        $this->travelTo($this->manila('2026-09-25 17:00'));
        $this->postJson('/api/attendance/time-out')
            ->assertOk()
            ->assertJsonPath('data.attendance.worked_minutes', 180)
            ->assertJsonPath('data.attendance.regular_amount', 288.46)
            ->assertJsonPath('data.attendance.salary_amount', 288.46);
    }

    public function test_attendance_works_without_salary_configuration(): void
    {
        $this->actingAsTracker(withSalary: false);
        $this->travelTo($this->manila('2026-09-25 08:00'));
        $this->postJson('/api/attendance/time-in')->assertOk();
        $this->travelTo($this->manila('2026-09-25 17:00'));

        $this->postJson('/api/attendance/time-out')
            ->assertOk()
            ->assertJsonPath('data.attendance.worked_minutes', 480)
            ->assertJsonPath('data.attendance.salary_amount', null);
    }

    public function test_time_in_is_allowed_on_a_rest_day(): void
    {
        $this->actingAsTracker();
        $this->travelTo($this->manila('2026-09-27 09:00')); // Sunday

        $this->postJson('/api/attendance/time-in')
            ->assertOk()
            ->assertJsonPath('data.attendance.scheduled_start', null)
            ->assertJsonPath('data.attendance.status', 'present');
    }

    public function test_offline_sync_is_idempotent_and_uses_client_timestamp(): void
    {
        $this->actingAsTracker();
        $this->travelTo($this->manila('2026-09-25 10:00'));
        $occurredAt = $this->manila('2026-09-25 08:05')->toIso8601String();
        $payload = ['idempotency_key' => 'tin-abc-123', 'occurred_at' => $occurredAt, 'source' => 'sync'];

        $first = $this->postJson('/api/attendance/time-in', $payload);
        $first->assertOk()
            ->assertJsonPath('data.already_recorded', false)
            ->assertJsonPath('data.attendance.time_in_label', '8:05 AM');

        $second = $this->postJson('/api/attendance/time-in', $payload);
        $second->assertOk()
            ->assertJsonPath('data.already_recorded', true)
            ->assertJsonPath('data.attendance.id', $first->json('data.attendance.id'));

        $this->assertSame(1, AttendanceRecord::count());

        $out = ['idempotency_key' => 'tout-abc-123', 'occurred_at' => $this->manila('2026-09-25 17:00')->toIso8601String(), 'source' => 'sync'];
        $this->postJson('/api/attendance/time-out', $out)->assertOk()->assertJsonPath('data.already_recorded', false);
        $this->postJson('/api/attendance/time-out', $out)->assertOk()->assertJsonPath('data.already_recorded', true);
        $this->assertSame(1, AttendanceRecord::count());
    }

    public function test_client_timestamp_is_ignored_for_online_actions(): void
    {
        $this->actingAsTracker();
        $this->travelTo($this->manila('2026-09-25 09:30'));

        $this->postJson('/api/attendance/time-in', [
            'occurred_at' => $this->manila('2026-09-25 07:00')->toIso8601String(),
            'source' => 'app',
        ])->assertOk()->assertJsonPath('data.attendance.time_in_label', '9:30 AM');
    }

    public function test_manual_attendance_entry_and_update(): void
    {
        $this->actingAsTracker();
        $this->travelTo($this->manila('2026-09-26 20:00'));

        $response = $this->postJson('/api/attendance/manual', [
            'work_date' => '2026-09-24',
            'time_in' => '08:00',
            'time_out' => '17:30',
            'notes' => 'Forgot to time in',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.source', 'manual')
            ->assertJsonPath('data.worked_minutes', 510)
            ->assertJsonPath('data.regular_minutes', 480)
            ->assertJsonPath('data.overtime_minutes', 30)
            ->assertJsonPath('data.salary_amount', 829.33);

        $id = $response->json('data.id');
        $this->putJson("/api/attendance/{$id}", ['time_out' => '17:00'])
            ->assertOk()
            ->assertJsonPath('data.overtime_minutes', 0)
            ->assertJsonPath('data.salary_amount', 769.23);

        $this->postJson('/api/attendance/manual', ['work_date' => '2026-09-23', 'status' => 'absent'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'absent')
            ->assertJsonPath('data.salary_amount', null);
    }

    public function test_forgotten_time_out_is_marked_incomplete_and_does_not_block_next_day(): void
    {
        $this->actingAsTracker();
        $this->travelTo($this->manila('2026-09-24 08:00'));
        $this->postJson('/api/attendance/time-in')->assertOk();

        // Next morning: yesterday's open record becomes "incomplete", today's time in works.
        $this->travelTo($this->manila('2026-09-25 08:00'));
        $this->postJson('/api/attendance/time-in')->assertOk()->assertJsonPath('data.attendance.work_date', '2026-09-25');

        $this->assertDatabaseHas('attendance_records', ['work_date' => '2026-09-24', 'status' => 'incomplete']);
    }

    public function test_leave_records_mark_attendance(): void
    {
        $this->actingAsTracker();
        $this->travelTo($this->manila('2026-09-25 08:00'));

        $this->postJson('/api/leave-records', ['leave_date' => '2026-09-25', 'type' => 'sick_leave', 'notes' => 'Fever'])
            ->assertCreated();

        $this->assertDatabaseHas('attendance_records', ['work_date' => '2026-09-25', 'status' => 'leave']);
        $this->getJson('/api/attendance/today')->assertOk()->assertJsonPath('data.state', 'LEAVE');
    }

    public function test_calendar_and_history_endpoints(): void
    {
        $this->actingAsTracker();
        $this->travelTo($this->manila('2026-09-25 08:00'));
        $this->postJson('/api/attendance/time-in')->assertOk();
        $this->travelTo($this->manila('2026-09-25 17:00'));
        $this->postJson('/api/attendance/time-out')->assertOk();

        $this->getJson('/api/attendance?range=month')->assertOk()->assertJsonCount(1, 'data.records');

        $calendar = $this->getJson('/api/calendar?year=2026&month=9')->assertOk();
        $this->assertCount(30, $calendar->json('data.days'));
        $day = collect($calendar->json('data.days'))->firstWhere('date', '2026-09-25');
        $this->assertSame('present', $day['status']);
        $this->assertSame(769.23, $day['salary']);
        $sunday = collect($calendar->json('data.days'))->firstWhere('date', '2026-09-27');
        $this->assertSame('rest_day', $sunday['status']);
    }
}
