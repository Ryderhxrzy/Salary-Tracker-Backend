<?php

namespace App\Services;

use App\Http\Resources\AttendanceRecordResource;
use App\Http\Resources\SalaryPeriodResource;
use App\Http\Resources\WalletResource;
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
        protected WalletService $wallets,
        protected SavingsService $savings,
        protected LoanService $loans,
        protected SalaryReceiptService $receipts,
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

        // Live hours while on duty (as if timed out right now); pay is only final at time out.
        $provisional = null;
        if ($record && $record->isOnDuty()) {
            $provisional = $this->attendance->provisional($user, $record, $now);
        }
        $effective = $provisional ?? $record;

        $todayExpenses = $this->expenses->totalBetween($user, $todayDate, $todayDate);

        // Current cut-off + the previous one while it is still waiting for its payday.
        $period = $this->periods->currentPeriod($user);
        $summary = $this->periods->compute($user, $period);
        $previous = $this->periods->previousPeriod($user, $period);
        $previousSummary = $this->periods->compute($user, $previous);
        $payday = $previousSummary['status'] === SalaryPeriodService::STATUS_COMPLETED
            ? ['period' => new SalaryPeriodResource($previous), 'summary' => $previousSummary]
            : null;

        // The nearest payday: the completed cut-off's if it has not been paid yet, else the current one's.
        $nextPayday = $payday
            ? ['date' => $previousSummary['pay_date'], 'days_until' => $previousSummary['days_until_pay'], 'amount' => $previousSummary['take_home'], 'period_name' => $previous->name, 'kind' => 'completed']
            : ['date' => $summary['pay_date'], 'days_until' => $summary['days_until_pay'], 'amount' => $summary['take_home'], 'period_name' => $period->name, 'kind' => 'current'];

        $recent = $user->attendanceRecords()->orderByDesc('work_date')->limit(5)->get();

        // Where the money is: salary − expenses − savings for this cut-off, wallets and total savings.
        $wallets = $this->wallets->withBalances($user);
        $goals = $user->savingsGoals()->where('type', '!=', 'spending_limit')->get(['current_amount']);
        $totalSaved = Money::sum($goals->pluck('current_amount')) + $this->savings->netBetween($user, null, null, withoutGoal: true);

        $schedule = $window ? [
            'start' => $window['start']->toIso8601String(),
            'end' => $window['end']->toIso8601String(),
            'start_label' => $window['start']->format('g:i A'),
            'end_label' => $window['end']->format('g:i A'),
            'break_minutes' => $window['break_minutes'],
            'break_start' => $window['break_start']?->toIso8601String(),
            'break_end' => $window['break_end']?->toIso8601String(),
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
            'next_payday' => $nextPayday,
            // Finished cut-offs waiting for "Receive salary" (oldest first; several can wait at once).
            'pending_salary' => $this->receipts->pending($user),
            'recent_attendance' => AttendanceRecordResource::collection($recent),
            'money' => [
                'income' => $summary['take_home'],
                'other_income' => $summary['other_income'],
                'expenses' => $summary['expenses'],
                'savings' => $summary['savings'],
                'remaining' => $summary['remaining'],
                'total_saved' => Money::round($totalSaved),
                'wallets' => WalletResource::collection($wallets),
                'loans' => $this->loans->totals($user) + [
                    'paid_this_period' => Money::round($summary['loan_payments'] + $summary['loan_deductions']),
                    'received_this_period' => $summary['loan_received'],
                ],
            ],
            'salary' => $this->salary->rates($user),
            'notification' => $this->notifications->plan($user, $state),
        ];
    }
}
