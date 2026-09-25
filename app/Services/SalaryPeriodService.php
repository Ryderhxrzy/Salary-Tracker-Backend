<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\LeaveRecord;
use App\Models\Loan;
use App\Models\SalaryAdjustment;
use App\Models\SalaryPeriod;
use App\Models\SalarySetting;
use App\Models\SavingsTransaction;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Pay periods (cut-offs) and the one salary computation every screen uses.
 *
 *   Expected / final salary = basic salary
 *                           − absences × daily rate
 *                           − undertime (late, half days) at the hourly rate
 *                           + overtime pay (+ pay for work on rest days)
 *
 * The same numbers feed the dashboard, the salary tab, the history and the
 * notification plan, so they can never disagree.
 */
class SalaryPeriodService
{
    public const STATUS_UPCOMING = 'upcoming';

    public const STATUS_ONGOING = 'ongoing';

    public const STATUS_COMPLETED = 'completed'; // ended, waiting for payday

    public const STATUS_PAID = 'paid';

    public function __construct(
        protected SalaryService $salary,
        protected WorkScheduleService $schedules,
    ) {}

    // ---------------------------------------------------------------------
    // Period bounds
    // ---------------------------------------------------------------------

    /**
     * @return array{start: CarbonImmutable, end: CarbonImmutable, type: string}
     */
    public function boundsFor(SalarySetting $settings, CarbonInterface $dateInUserTz): array
    {
        $date = CarbonImmutable::parse($dateInUserTz->toDateString(), $dateInUserTz->getTimezone());
        $type = $settings->period_type ?: 'semi_monthly';

        switch ($type) {
            case 'weekly':
                $weekday = (int) $settings->period_start_weekday; // 0=Sun
                $diff = ($date->dayOfWeek - $weekday + 7) % 7;
                $start = $date->subDays($diff);
                $end = $start->addDays(6);
                break;

            case 'biweekly':
            case 'custom':
                $length = $type === 'biweekly' ? 14 : max(1, (int) $settings->custom_period_days);
                $anchor = $settings->period_anchor_date
                    ? CarbonImmutable::parse($settings->period_anchor_date->toDateString(), $date->getTimezone())
                    : $date->startOfYear();
                $days = (int) $anchor->diffInDays($date, false);
                $cycles = (int) floor($days / $length);
                $start = $anchor->addDays($cycles * $length);
                $end = $start->addDays($length - 1);
                break;

            case 'monthly':
                $startDay = min(28, max(1, (int) $settings->period_start_day));
                $start = $date->day >= $startDay ? $date->day($startDay) : $date->subMonthNoOverflow()->day($startDay);
                $end = $start->addMonthNoOverflow()->subDay();
                break;

            case 'semi_monthly':
            default:
                // Two cut-offs per month, e.g. 11th-25th and 26th-10th of the next month.
                $type = 'semi_monthly';
                $first = min(28, max(1, (int) $settings->period_start_day));
                $second = min(28, max($first + 1, (int) $settings->period_second_day));
                if ($date->day >= $second) {
                    $start = $date->day($second);
                    $end = $date->addMonthNoOverflow()->day($first)->subDay();
                } elseif ($date->day >= $first) {
                    $start = $date->day($first);
                    $end = $date->day($second)->subDay();
                } else {
                    $start = $date->subMonthNoOverflow()->day($second);
                    $end = $date->day($first)->subDay();
                }
                break;
        }

        return ['start' => $start->startOfDay(), 'end' => $end->startOfDay(), 'type' => $type];
    }

    /**
     * When a cut-off is paid: end date + pay_delay_days. Semi-monthly cut-offs are
     * paid within the month they end (11-25 => 30th or month end, 26-10 => 15th).
     */
    public function payDateFor(SalarySetting $settings, CarbonInterface $end): CarbonImmutable
    {
        $end = CarbonImmutable::parse($end->toDateString(), $end->getTimezone());
        $pay = $end->addDays(max(0, (int) ($settings->pay_delay_days ?? 5)));
        if (($settings->period_type ?: 'semi_monthly') === 'semi_monthly' && ! $pay->isSameMonth($end)) {
            $pay = $end->endOfMonth()->startOfDay();
        }

        return $pay;
    }

    public function periodFor(User $user, ?CarbonInterface $dateInUserTz = null): SalaryPeriod
    {
        $settings = $this->salary->settings($user);
        $date = $dateInUserTz ?? CarbonImmutable::now($user->timezone());

        return $this->periodForBounds($user, $settings, $this->boundsFor($settings, $date));
    }

    public function currentPeriod(User $user): SalaryPeriod
    {
        return $this->periodFor($user);
    }

    /** The cut-off right before the given one. */
    public function previousPeriod(User $user, SalaryPeriod $period): SalaryPeriod
    {
        $start = CarbonImmutable::parse($period->start_date->toDateString(), $user->timezone());

        return $this->periodFor($user, $start->subDay());
    }

    /**
     * Current period plus the previous N periods (newest first).
     *
     * @return array<int, SalaryPeriod>
     */
    public function recentPeriods(User $user, int $count = 12): array
    {
        $tz = $user->timezone();
        $settings = $this->salary->settings($user);
        $cursor = CarbonImmutable::now($tz);
        $periods = [];

        for ($i = 0; $i < $count; $i++) {
            $bounds = $this->boundsFor($settings, $cursor);
            $periods[] = $this->periodForBounds($user, $settings, $bounds);
            $cursor = $bounds['start']->subDay();
        }

        return $periods;
    }

    /**
     * Every cut-off whose pay day falls inside [$from, $to] and that is already paid
     * (pay day before today). Used to credit received salaries to a wallet.
     *
     * @return array<int, SalaryPeriod>
     */
    public function periodsPaidBetween(User $user, string $from, string $to): array
    {
        $tz = $user->timezone();
        $settings = $this->salary->settings($user);
        $today = CarbonImmutable::now($tz)->toDateString();
        // Start a little earlier: a cut-off paid after $from may have started before it.
        $cursor = CarbonImmutable::parse($from, $tz)->subDays(45);
        $limit = CarbonImmutable::parse($to, $tz);
        $bounds = [];
        for ($i = 0; $i < 120 && $cursor->lessThanOrEqualTo($limit); $i++) {
            $b = $this->boundsFor($settings, $cursor);
            $payDate = $this->payDateFor($settings, $b['end'])->toDateString();
            if ($payDate >= $from && $payDate <= $to && $payDate < $today) {
                $bounds[] = $b;
            }
            $cursor = $b['end']->addDay();
        }
        if ($bounds === []) {
            return [];
        }

        // One query for the rows that already exist, then create the missing ones.
        $existing = $user->salaryPeriods()->whereIn('start_date', array_map(fn ($b) => $b['start']->toDateString(), $bounds))->get()
            ->keyBy(fn (SalaryPeriod $p) => $p->start_date->toDateString());
        $periods = [];
        foreach ($bounds as $b) {
            $period = $existing->get($b['start']->toDateString()) ?? $this->periodForBounds($user, $settings, $b);
            $period->pay_date = $this->payDateFor($settings, $b['end'])->toDateString();
            $period->period_status = $this->statusFor($user, $period);
            $periods[] = $period;
        }

        return $periods;
    }

    /**
     * @param  array{start: CarbonImmutable, end: CarbonImmutable, type: string}  $bounds
     */
    protected function periodForBounds(User $user, SalarySetting $settings, array $bounds): SalaryPeriod
    {
        $period = $user->salaryPeriods()->firstOrCreate(
            ['start_date' => $bounds['start']->toDateString(), 'end_date' => $bounds['end']->toDateString()],
            ['name' => $this->nameFor($bounds['start'], $bounds['end']), 'period_type' => $bounds['type']]
        );
        $period->pay_date = $this->payDateFor($settings, $bounds['end'])->toDateString();
        $period->period_status = $this->statusFor($user, $period);

        return $period;
    }

    /** upcoming | ongoing | completed (ended, waiting for payday) | paid */
    public function statusFor(User $user, SalaryPeriod $period): string
    {
        $today = CarbonImmutable::now($user->timezone())->toDateString();
        $from = $period->start_date->toDateString();
        $to = $period->end_date->toDateString();
        $payDate = $period->pay_date ?? $this->payDateFor($this->salary->settings($user), $period->end_date)->toDateString();

        return match (true) {
            $today < $from => self::STATUS_UPCOMING,
            $today <= $to => self::STATUS_ONGOING,
            $today <= $payDate => self::STATUS_COMPLETED,
            default => self::STATUS_PAID,
        };
    }

    // ---------------------------------------------------------------------
    // The salary computation
    // ---------------------------------------------------------------------

    /**
     * Everything about one pay period: attendance counts, deductions, overtime and
     * the expected (ongoing) or final (completed) salary.
     *
     * Rules, per scheduled working day of the period:
     *  - worked (time in + time out): pay = daily rate × hours ÷ expected hours,
     *    so a late arrival or a half day is deducted as undertime; overtime is added;
     *  - on duty today: assumed to be a full day until timed out;
     *  - paid leave: no deduction; unpaid leave or absent: one daily rate deducted;
     *  - a past working day with no record at all counts as absent;
     *  - today without a record and future days are assumed to be worked;
     *  - forgotten time out (incomplete): assumed a full day, flagged for correction;
     *  - work on a rest day is paid on top of the basic salary.
     */
    public function compute(User $user, SalaryPeriod $period): array
    {
        $settings = $this->salary->settings($user);
        $configured = $settings->isConfigured();
        $tz = $user->timezone();
        $today = CarbonImmutable::now($tz)->toDateString();
        $from = $period->start_date->toDateString();
        $to = $period->end_date->toDateString();
        $payDate = $period->pay_date ?? $this->payDateFor($settings, $period->end_date)->toDateString();
        $status = $period->period_status ?? $this->statusFor($user, $period);

        $schedules = $this->schedules->ensureDefaults($user)->keyBy('day_of_week');
        $leaves = $user->leaveRecords()->whereBetween('leave_date', [$from, $to])->get()
            ->keyBy(fn (LeaveRecord $leave) => $leave->leave_date->toDateString());
        $records = $user->attendanceRecords()->betweenDates($from, $to)->get()
            ->keyBy(fn (AttendanceRecord $record) => $record->work_date->toDateString());
        $adjustments = $user->salaryAdjustments()->forPeriod($from, $to)->get();
        $expenses = (float) $user->expenses()->whereBetween('expense_date', [$from, $to])->sum('amount');
        $savings = $this->savingsBetween($user, $from, $to);
        $loans = $this->loansBetween($user, $from, $to);
        $otherIncome = (float) $user->incomes()->whereBetween('income_date', [$from, $to])->sum('amount');

        $daily = $configured ? ($this->salary->dailyRate($user, $settings) ?? 0.0) : 0.0;

        $counts = [
            'working_days' => 0, 'days_done' => 0, 'days_remaining' => 0,
            'days_worked' => 0, 'days_late' => 0, 'days_absent' => 0, 'days_leave' => 0,
            'days_unpaid_leave' => 0, 'days_incomplete' => 0, 'days_rest_worked' => 0,
        ];
        $undertimeMinutes = 0;
        $undertimeDeduction = 0.0;
        $absenceDeduction = 0.0;
        $restDayPay = 0.0;

        // One entry per scheduled working day: worked | late | undertime | on_duty | incomplete |
        // leave | unpaid_leave | absent | today | upcoming. Drives the day strip in the app.
        $days = [];
        for ($day = CarbonImmutable::parse($from, $tz); $day->toDateString() <= $to; $day = $day->addDay()) {
            $date = $day->toDateString();
            $schedule = $schedules->get($day->dayOfWeek);
            /** @var AttendanceRecord|null $record */
            $record = $records->get($date);
            /** @var LeaveRecord|null $leave */
            $leave = $leaves->get($date);
            $worked = $record && $record->time_in && in_array($record->status, [AttendanceRecord::STATUS_PRESENT, AttendanceRecord::STATUS_LATE], true);

            $isWorkingDay = $schedule && $schedule->is_working_day
                && ! ($leave && in_array($leave->type, ['rest_day', 'holiday'], true))
                && $record?->status !== AttendanceRecord::STATUS_REST_DAY;

            if (! $isWorkingDay) {
                if ($worked) {
                    // Worked on a rest day / holiday: paid on top of the basic salary.
                    $counts['days_rest_worked']++;
                    $restDayPay += (float) ($record->regular_amount ?? 0);
                }

                continue;
            }

            $counts['working_days']++;
            $completedToday = $date === $today && $record && $record->time_out;
            if ($date < $today || $completedToday) {
                $counts['days_done']++;
            } else {
                $counts['days_remaining']++;
            }

            $recordStatus = $record?->status;
            if ($record && $record->time_in && $recordStatus === AttendanceRecord::STATUS_INCOMPLETE) {
                $counts['days_incomplete']++; // forgot to time out: assumed a full day, needs correction
                $dayStatus = 'incomplete';
            } elseif ($worked) {
                $counts['days_worked']++;
                if ($recordStatus === AttendanceRecord::STATUS_LATE) {
                    $counts['days_late']++;
                }
                if ($record->time_out) {
                    // Hours short of a full day are deducted at the hourly rate (daily ÷ expected hours).
                    $expected = $schedule->expectedWorkMinutes();
                    $short = max(0, $expected - (int) $record->regular_minutes);
                    $undertimeMinutes += $short;
                    if ($configured && $expected > 0) {
                        $undertimeDeduction += $daily * $short / $expected;
                    }
                    $dayStatus = $recordStatus === AttendanceRecord::STATUS_LATE ? 'late' : ($short > 0 ? 'undertime' : 'worked');
                } else {
                    $dayStatus = 'on_duty'; // full day until timed out
                }
            } elseif ($leave && $leave->type !== 'absent') {
                // Leave records mirror onto the attendance record; the leave record knows whether it is paid.
                if ($leave->is_paid) {
                    $counts['days_leave']++;
                    $dayStatus = 'leave';
                } else {
                    $counts['days_unpaid_leave']++;
                    $absenceDeduction += $daily;
                    $dayStatus = 'unpaid_leave';
                }
            } elseif ($recordStatus === AttendanceRecord::STATUS_LEAVE) {
                $counts['days_leave']++; // manual leave without a leave record: treated as paid
                $dayStatus = 'leave';
            } elseif ($recordStatus === AttendanceRecord::STATUS_ABSENT || ($leave && $leave->type === 'absent') || $date < $today) {
                $counts['days_absent']++;
                $absenceDeduction += $daily;
                $dayStatus = 'absent';
            } else {
                // Today without a record and future days: still expected to be worked.
                $dayStatus = $date === $today ? 'today' : 'upcoming';
            }

            $days[] = ['date' => $date, 'status' => $dayStatus];
        }

        // A finished period with no attendance, leave or adjustment at all was simply not tracked
        // (e.g. before the app was used): it gets no salary instead of a full set of absences.
        $tracked = $records->isNotEmpty() || $leaves->isNotEmpty() || $adjustments->where('recurring', false)->isNotEmpty()
            || in_array($status, [self::STATUS_ONGOING, self::STATUS_UPCOMING], true);

        $overtimePay = Money::sum($records->pluck('overtime_amount'));
        $basic = $configured ? $this->salary->basicForPeriod($user, $counts['working_days'], $settings) : null;
        // Deductions can never take the basic pay below zero; overtime is always added on top.
        $salary = $configured && $tracked
            ? Money::round(max(0.0, ($basic ?? 0.0) - $absenceDeduction - $undertimeDeduction) + $overtimePay + $restDayPay)
            : null;

        $income = Money::sum($adjustments->filter(fn (SalaryAdjustment $a) => $a->isIncome())->pluck('amount'));
        $deductions = Money::sum($adjustments->filter(fn (SalaryAdjustment $a) => ! $a->isIncome())->pluck('amount'));
        // Loan payments the employer takes from the payslip lower the take-home pay.
        $takeHome = $configured ? Money::round(($salary ?? 0.0) + $income - $deductions - $loans['payroll']) : Money::round($income - $deductions - $loans['payroll']);

        $payDay = CarbonImmutable::parse($payDate, $tz);
        $daysUntilPay = (int) CarbonImmutable::parse($today, $tz)->diffInDays($payDay, false);
        // "Receive salary": the user confirms the pay arrived; until then nothing is credited to a wallet.
        $receipt = $user->salaryReceipts()->where('period_from', $from)->first(['id', 'amount', 'received_date']);

        return $counts + [
            'from' => $from,
            'to' => $to,
            'pay_date' => $payDate,
            'status' => $status,
            'days_until_pay' => $daysUntilPay,
            'is_current' => $status === self::STATUS_ONGOING,
            'tracked' => $tracked,
            'salary_configured' => $configured,
            'basic_salary' => $basic,
            'daily_rate' => $configured ? Money::round($daily) : null,
            'worked_minutes' => (int) $records->sum('worked_minutes'),
            'regular_minutes' => (int) $records->sum('regular_minutes'),
            'overtime_minutes' => (int) $records->sum('overtime_minutes'),
            'late_minutes' => (int) $records->sum('late_minutes'),
            'undertime_minutes' => $undertimeMinutes,
            'absence_deduction' => $configured ? Money::round($absenceDeduction) : null,
            'undertime_deduction' => $configured ? Money::round($undertimeDeduction) : null,
            'overtime_pay' => $configured ? $overtimePay : null,
            'rest_day_pay' => $configured ? Money::round($restDayPay) : null,
            'salary' => $salary,
            'earned_to_date' => $configured ? Money::sum($records->pluck('salary_amount')) : null,
            'additional_income' => $income,
            'deductions' => $deductions,
            'take_home' => $takeHome,
            'expenses' => Money::round($expenses),
            // Money set aside into savings during the period (deposits − withdrawals).
            'savings' => Money::round($savings),
            // Loan payments taken from the payslip (already inside take_home), paid from a wallet, and repayments received.
            'loan_deductions' => $loans['payroll'],
            'loan_payments' => $loans['paid'],
            'loan_received' => $loans['received'],
            // Money earned outside the salary (side hustle, freelance, gifts…).
            'other_income' => Money::round($otherIncome),
            // Left to spend: take-home + other income − expenses − savings − loan payments + repayments received.
            'remaining' => Money::round($takeHome + $otherIncome - $expenses - $savings - $loans['paid'] + $loans['received']),
            'progress' => $counts['working_days'] > 0 ? round($counts['days_done'] / $counts['working_days'], 4) : 0.0,
            'received' => $receipt !== null,
            'received_at' => $receipt?->received_date?->toDateString(),
            'received_amount' => $receipt ? (float) $receipt->amount : null,
            'receipt_id' => $receipt?->id,
            'days' => $days,
        ];
    }

    /**
     * Attendance + money totals for any inclusive date range (statistics, custom
     * ranges). Sums what the days actually earned; the period salary with its basic
     * pay and deductions is compute().
     */
    public function summary(User $user, string $from, string $to): array
    {
        $settings = $this->salary->settings($user);
        $configured = $settings->isConfigured();

        $records = $user->attendanceRecords()->betweenDates($from, $to)->get();
        // Recurring entries are counted once here (a range may span several paydays; the period computation is exact).
        $adjustments = $user->salaryAdjustments()->forPeriod($from, $to)->get();
        $expenses = (float) $user->expenses()->whereBetween('expense_date', [$from, $to])->sum('amount');
        $savings = $this->savingsBetween($user, $from, $to);
        $loans = $this->loansBetween($user, $from, $to);
        $otherIncome = (float) $user->incomes()->whereBetween('income_date', [$from, $to])->sum('amount');

        $regular = Money::sum($records->pluck('regular_amount'));
        $overtimePay = Money::sum($records->pluck('overtime_amount'));
        $salary = Money::sum($records->pluck('salary_amount'));

        $income = Money::sum($adjustments->filter(fn (SalaryAdjustment $a) => $a->isIncome())->pluck('amount'));
        $deductions = Money::sum($adjustments->filter(fn (SalaryAdjustment $a) => ! $a->isIncome())->pluck('amount'));

        $absentDays = $records->where('status', AttendanceRecord::STATUS_ABSENT)->count();
        $absenceDeduction = $configured ? (Money::round(($this->salary->dailyRate($user, $settings) ?? 0) * $absentDays) ?? 0.0) : 0.0;

        $totalIncome = Money::round($salary + $income) ?? 0.0;
        $remaining = Money::round($totalIncome + $otherIncome - $deductions - $loans['payroll'] - $expenses - $savings - $loans['paid'] + $loans['received']) ?? 0.0;

        $worked = $records->filter(fn (AttendanceRecord $r) => in_array($r->status, [AttendanceRecord::STATUS_PRESENT, AttendanceRecord::STATUS_LATE], true));

        return [
            'from' => $from,
            'to' => $to,
            'salary_configured' => $configured,
            'regular_pay' => $configured ? $regular : null,
            'overtime_pay' => $configured ? $overtimePay : null,
            'salary_earned' => $configured ? $salary : null,
            'additional_income' => $income,
            'deductions' => $deductions,
            'absence_deduction' => $absenceDeduction,
            'total_income' => $configured ? $totalIncome : $income,
            'expenses' => Money::round($expenses),
            'savings' => Money::round($savings),
            'loan_deductions' => $loans['payroll'],
            'loan_payments' => $loans['paid'],
            'loan_received' => $loans['received'],
            'other_income' => Money::round($otherIncome),
            'remaining' => $configured ? $remaining : Money::round($income + $otherIncome - $deductions - $loans['payroll'] - $expenses - $savings - $loans['paid'] + $loans['received']),
            'days_worked' => $worked->count(),
            'days_absent' => $absentDays,
            'days_late' => $records->where('status', AttendanceRecord::STATUS_LATE)->count(),
            'days_leave' => $records->where('status', AttendanceRecord::STATUS_LEAVE)->count(),
            'days_incomplete' => $records->where('status', AttendanceRecord::STATUS_INCOMPLETE)->count(),
            'worked_minutes' => (int) $records->sum('worked_minutes'),
            'regular_minutes' => (int) $records->sum('regular_minutes'),
            'overtime_minutes' => (int) $records->sum('overtime_minutes'),
            'late_minutes' => (int) $records->sum('late_minutes'),
        ];
    }

    /** Deposits − withdrawals dated inside the range. */
    protected function savingsBetween(User $user, string $from, string $to): float
    {
        $rows = $user->savingsTransactions()->whereBetween('transaction_date', [$from, $to])
            ->selectRaw('type, SUM(amount) as total')->groupBy('type')->pluck('total', 'type');

        return (float) ($rows[SavingsTransaction::TYPE_DEPOSIT] ?? 0) - (float) ($rows[SavingsTransaction::TYPE_WITHDRAWAL] ?? 0);
    }

    /**
     * Loan payments dated inside the range: paid from a wallet, taken from the payslip, or received back.
     *
     * @return array{paid: float, payroll: float, received: float}
     */
    protected function loansBetween(User $user, string $from, string $to): array
    {
        $rows = $user->loanPayments()
            ->join('loans', 'loans.id', '=', 'loan_payments.loan_id')
            ->whereNull('loans.deleted_at')
            ->whereBetween('loan_payments.payment_date', [$from, $to])
            ->selectRaw('loans.type as loan_type, loan_payments.via_payroll as payroll, SUM(loan_payments.amount) as total')
            ->groupBy('loans.type', 'loan_payments.via_payroll')
            ->get();

        $out = ['paid' => 0.0, 'payroll' => 0.0, 'received' => 0.0];
        foreach ($rows as $row) {
            $total = (float) $row->total;
            if ($row->loan_type === Loan::TYPE_LENT) {
                $out['received'] += $total;
            } elseif ((bool) $row->payroll) {
                $out['payroll'] += $total;
            } else {
                $out['paid'] += $total;
            }
        }

        return array_map(fn ($v) => Money::round($v) ?? 0.0, $out);
    }

    public function nameFor(CarbonInterface $start, CarbonInterface $end): string
    {
        if ($start->isSameMonth($end)) {
            return $start->format('M j').' - '.$end->format('j, Y');
        }
        if ($start->isSameYear($end)) {
            return $start->format('M j').' - '.$end->format('M j, Y');
        }

        return $start->format('M j, Y').' - '.$end->format('M j, Y');
    }
}
