<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;

class StatisticsService
{
    public function __construct(
        protected SalaryPeriodService $periods,
        protected ExpenseService $expenses,
        protected WorkScheduleService $schedules,
        protected SalaryService $salary,
    ) {}

    /**
     * Resolve a named range ("today", "week", "month", "period", "custom") into dates.
     *
     * @return array{from: string, to: string, label: string}
     */
    public function resolveRange(User $user, string $range, ?string $from = null, ?string $to = null): array
    {
        $tz = $user->timezone();
        $today = CarbonImmutable::now($tz);

        return match ($range) {
            'today' => ['from' => $today->toDateString(), 'to' => $today->toDateString(), 'label' => 'Today'],
            'week' => ['from' => $today->startOfWeek(CarbonImmutable::MONDAY)->toDateString(), 'to' => $today->endOfWeek(CarbonImmutable::SUNDAY)->toDateString(), 'label' => 'This week'],
            'month' => ['from' => $today->startOfMonth()->toDateString(), 'to' => $today->endOfMonth()->toDateString(), 'label' => 'This month'],
            'custom' => ['from' => $from ?? $today->startOfMonth()->toDateString(), 'to' => $to ?? $today->toDateString(), 'label' => 'Custom range'],
            default => (function () use ($user) {
                $period = $this->periods->currentPeriod($user);

                return ['from' => $period->start_date->toDateString(), 'to' => $period->end_date->toDateString(), 'label' => $period->name];
            })(),
        };
    }

    public function build(User $user, string $from, string $to): array
    {
        $summary = $this->periods->summary($user, $from, $to);
        $records = $user->attendanceRecords()->betweenDates($from, $to)->get()->keyBy(fn (AttendanceRecord $r) => $r->work_date->toDateString());
        $expensesByDay = $this->expenses->byDay($user, $from, $to);
        $tz = $user->timezone();

        $series = [];
        $scheduledDays = 0;
        foreach (CarbonPeriod::create($from, $to) as $day) {
            $date = $day->toDateString();
            $local = CarbonImmutable::parse($date, $tz);
            $isWorking = $this->schedules->isWorkingDay($user, $local);
            if ($isWorking && $local->lessThanOrEqualTo(CarbonImmutable::now($tz))) {
                $scheduledDays++;
            }
            /** @var AttendanceRecord|null $record */
            $record = $records->get($date);
            $series[] = [
                'date' => $date,
                'is_working_day' => $isWorking,
                'status' => $record?->status ?? ($isWorking ? null : 'rest_day'),
                'worked_minutes' => $record?->worked_minutes ?? 0,
                'regular_minutes' => $record?->regular_minutes ?? 0,
                'overtime_minutes' => $record?->overtime_minutes ?? 0,
                'salary' => Money::round($record?->salary_amount),
                'expenses' => $expensesByDay[$date] ?? 0.0,
            ];
        }

        $daysWorked = $summary['days_worked'];
        $averageMinutes = $daysWorked > 0 ? (int) round($summary['worked_minutes'] / $daysWorked) : 0;

        return [
            'range' => ['from' => $from, 'to' => $to],
            'summary' => $summary + [
                'scheduled_days' => $scheduledDays,
                'average_minutes_per_day' => $averageMinutes,
            ],
            'series' => $series,
            'expenses_by_category' => $this->expenses->byCategory($user, $from, $to),
        ];
    }

    /**
     * Month calendar with one entry per day.
     */
    public function calendar(User $user, int $year, int $month): array
    {
        $tz = $user->timezone();
        $start = CarbonImmutable::create($year, $month, 1, 0, 0, 0, $tz);
        $end = $start->endOfMonth();
        $from = $start->toDateString();
        $to = $end->toDateString();

        $records = $user->attendanceRecords()->betweenDates($from, $to)->get()->keyBy(fn (AttendanceRecord $r) => $r->work_date->toDateString());
        $leaves = $user->leaveRecords()->whereBetween('leave_date', [$from, $to])->get()->keyBy(fn ($l) => $l->leave_date->toDateString());
        $expensesByDay = $this->expenses->byDay($user, $from, $to);
        $today = CarbonImmutable::now($tz)->toDateString();

        $days = [];
        foreach (CarbonPeriod::create($from, $to) as $day) {
            $date = $day->toDateString();
            $local = CarbonImmutable::parse($date, $tz);
            $window = $this->schedules->windowForDate($user, $local);
            /** @var AttendanceRecord|null $record */
            $record = $records->get($date);
            $leave = $leaves->get($date);

            $status = $record?->status;
            if (! $status) {
                $status = match (true) {
                    $leave !== null => $leave->attendanceStatus(),
                    $window === null => 'rest_day',
                    $date > $today => 'scheduled',
                    $date === $today => 'today',
                    default => 'no_record',
                };
            }

            $days[] = [
                'date' => $date,
                'day' => (int) $local->day,
                'day_of_week' => (int) $local->dayOfWeek,
                'is_today' => $date === $today,
                'is_working_day' => $window !== null,
                'status' => $status,
                'schedule' => $window ? ['start_label' => $window['start']->format('g:i A'), 'end_label' => $window['end']->format('g:i A')] : null,
                'leave' => $leave ? ['type' => $leave->type, 'notes' => $leave->notes, 'is_paid' => $leave->is_paid] : null,
                'time_in' => $record?->time_in?->toIso8601String(),
                'time_out' => $record?->time_out?->toIso8601String(),
                'worked_minutes' => $record?->worked_minutes ?? 0,
                'overtime_minutes' => $record?->overtime_minutes ?? 0,
                'salary' => Money::round($record?->salary_amount),
                'expenses' => $expensesByDay[$date] ?? 0.0,
                'attendance_id' => $record?->id,
            ];
        }

        return [
            'year' => $year,
            'month' => $month,
            'from' => $from,
            'to' => $to,
            'days' => $days,
            'summary' => $this->periods->summary($user, $from, $to),
        ];
    }
}
