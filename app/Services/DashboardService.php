<?php

namespace App\Services;

use App\Http\Resources\AttendanceRecordResource;
use App\Http\Resources\SalaryPeriodResource;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Support\Money;
use Carbon\CarbonImmutable;

class DashboardService
{
    public function __construct(
        protected AttendanceService $attendance,
        protected SalaryService $salary,
        protected SalaryPeriodService $periods,
        protected ExpenseService $expenses,
        protected NotificationService $notifications,
        protected WorkScheduleService $schedules,
    ) {}

    public function build(User $user): array
    {
        $user->loadMissing(['profile', 'salarySetting', 'notificationSetting']);
        $this->schedules->ensureDefaults($user);
        $this->expenses->ensureDefaultCategories($user);

        $tz = $user->timezone();
        $now = CarbonImmutable::now('UTC');
        $today = $now->setTimezone($tz);
        $todayDate = $today->toDateString();

        $state = $this->attendance->todayState($user, $now);
        $record = $state['record'];
        $window = $state['window'];
        $settings = $this->salary->settings($user);
        $configured = $settings->isConfigured();

        $provisional = null;
        if ($record && $record->isOnDuty()) {
            $provisional = $this->attendance->provisional($user, $record, $now);
        }
        $effective = $provisional ?? $record;

        $todayExpenses = $this->expenses->totalBetween($user, $todayDate, $todayDate);

        $period = $this->periods->currentPeriod($user);
        $summary = $this->periods->details($user, $period);

        // The previous cut-off stays on the dashboard until its pay date, then only in history.
        $previous = $this->periods->periodFor($user, CarbonImmutable::parse($period->start_date->toDateString(), $tz)->subDay());
        $payday = $previous->pay_date >= $todayDate ? [
            'period' => new SalaryPeriodResource($previous),
            'summary' => $this->periods->details($user, $previous),
        ] : null;

        $schedule = $window ? [
            'start' => $window['start']->toIso8601String(),
            'end' => $window['end']->toIso8601String(),
            'start_label' => $window['start']->format('g:i A'),
            'end_label' => $window['end']->format('g:i A'),
            'break_minutes' => $window['break_minutes'],
            'expected_minutes' => $window['expected_minutes'],
        ] : null;

        return [
            'server_time' => $now->toIso8601String(),
            'timezone' => $tz,
            'profile' => [
                'name' => $user->profile?->nickname ?: ($user->profile?->full_name ?: $user->name),
                'full_name' => $user->profile?->full_name ?: $user->name,
                'position' => $user->profile?->position,
                'company' => $user->profile?->company,
            ],
            'today' => [
                'date' => $todayDate,
                'day_name' => WorkSchedule::DAY_NAMES[$today->dayOfWeek],
                'state' => $state['state'],
                'is_working_day' => $window !== null && $state['leave'] === null,
                'schedule' => $schedule,
                'leave' => $state['leave'] ? ['type' => $state['leave']->type, 'notes' => $state['leave']->notes] : null,
                'attendance' => $record ? new AttendanceRecordResource($record) : null,
                'worked_minutes' => $effective?->worked_minutes ?? 0,
                'regular_minutes' => $effective?->regular_minutes ?? 0,
                'overtime_minutes' => $effective?->overtime_minutes ?? 0,
                'late_minutes' => $effective?->late_minutes ?? 0,
                // Pay is only computed at time out; while on duty only the hours are live.
                'earned' => $configured && ! $provisional ? Money::round($record?->salary_amount) : null,
                'is_live' => $provisional !== null,
                'expenses' => $todayExpenses,
            ],
            'period' => [
                'period' => new SalaryPeriodResource($period),
                'summary' => $summary,
            ],
            'payday' => $payday,
            'salary' => $this->salary->rates($user),
            'notification' => $this->notifications->plan($user, $state),
        ];
    }
}
