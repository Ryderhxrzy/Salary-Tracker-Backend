<?php

namespace App\Services;

use App\Models\NotificationSetting;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Builds the "notification plan" the mobile app uses to schedule its
 * persistent Android work notification and reminders. The plan is derived
 * from the user's backend schedule, attendance state and settings.
 */
class NotificationService
{
    public function __construct(
        protected AttendanceService $attendance,
        protected WorkScheduleService $schedules,
        protected SalaryPeriodService $periods,
    ) {}

    public function settings(User $user): NotificationSetting
    {
        $settings = $user->relationLoaded('notificationSetting') && $user->notificationSetting
            ? $user->notificationSetting
            : $user->notificationSetting()->firstOrCreate([]);
        if ($settings->wasRecentlyCreated) {
            $settings = $settings->fresh(); // pick up database defaults
        }
        $user->setRelation('notificationSetting', $settings);

        return $settings;
    }

    public function plan(User $user, ?array $todayState = null): array
    {
        $settings = $this->settings($user);
        $tz = $user->timezone();
        $now = CarbonImmutable::now('UTC');
        $today = $now->setTimezone($tz);
        $lead = (int) $settings->work_notification_lead_minutes;

        $state = $todayState ?? $this->attendance->todayState($user, $now);
        $record = $state['record'];
        $window = $state['window'];

        $current = [
            'state' => $state['state'],
            'date' => $state['date'],
            'time_in' => $record?->time_in?->toIso8601String(),
            'time_out' => $record?->time_out?->toIso8601String(),
            'scheduled_start' => $window ? $window['start']->toIso8601String() : null,
            'scheduled_end' => $window ? $window['end']->toIso8601String() : null,
            'break_start' => $window ? $window['break_start']?->toIso8601String() : null,
            'break_end' => $window ? $window['break_end']?->toIso8601String() : null,
            'show_at' => $window ? $window['start']->subMinutes($lead)->toIso8601String() : null,
            'time_in_label' => $record?->time_in?->setTimezone($tz)->format('g:i A'),
        ];

        $upcoming = [];
        $days = (int) config('salary_tracker.notification_plan_days', 14);
        for ($i = 1; $i <= $days; $i++) {
            $date = $today->addDays($i);
            if (! $this->schedules->isWorkingDay($user, $date)) {
                continue;
            }
            $w = $this->schedules->windowForDate($user, $date);
            $upcoming[] = [
                'date' => $date->toDateString(),
                'scheduled_start' => $w['start']->toIso8601String(),
                'scheduled_end' => $w['end']->toIso8601String(),
                'break_start' => $w['break_start']?->toIso8601String(),
                'break_end' => $w['break_end']?->toIso8601String(),
                'show_at' => $w['start']->subMinutes($lead)->toIso8601String(),
            ];
        }

        $period = $this->periods->currentPeriod($user);

        return [
            'enabled' => (bool) $settings->work_notification,
            'timezone' => $tz,
            'server_time' => $now->toIso8601String(),
            'current' => $current,
            'upcoming' => $upcoming,
            'reminders' => [
                'before_work' => ['enabled' => (bool) $settings->before_work_reminder, 'minutes' => (int) $settings->before_work_minutes],
                'still_on_duty' => ['enabled' => (bool) $settings->still_on_duty_reminder, 'minutes' => (int) $settings->still_on_duty_minutes],
                'salary_period' => ['enabled' => (bool) $settings->salary_period_reminder, 'period_end' => $period->end_date->toDateString()],
                'expense' => ['enabled' => (bool) $settings->expense_reminder, 'time' => substr((string) $settings->expense_reminder_time, 0, 5)],
            ],
        ];
    }
}
