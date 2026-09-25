<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\SalaryAdjustment;
use App\Models\SalaryPeriod;
use App\Models\SalarySetting;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class SalaryPeriodService
{
    public function __construct(protected SalaryService $salary) {}

    /**
     * @return array{start: CarbonImmutable, end: CarbonImmutable, type: string}
     */
    public function boundsFor(SalarySetting $settings, CarbonInterface $dateInUserTz): array
    {
        $date = CarbonImmutable::parse($dateInUserTz->toDateString(), $dateInUserTz->getTimezone());
        $type = $settings->period_type ?: 'monthly';

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

            case 'semi_monthly':
                // Two cut-offs per month, e.g. 11th-25th and 26th-10th of the next month.
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

            case 'monthly':
            default:
                $startDay = min(28, max(1, (int) $settings->period_start_day));
                $start = $date->day >= $startDay ? $date->day($startDay) : $date->subMonthNoOverflow()->day($startDay);
                $end = $start->addMonthNoOverflow()->subDay();
                $type = 'monthly';
                break;
        }

        return ['start' => $start->startOfDay(), 'end' => $end->startOfDay(), 'type' => $type];
    }

    public function periodFor(User $user, ?CarbonInterface $dateInUserTz = null): SalaryPeriod
    {
        $settings = $this->salary->settings($user);
        $date = $dateInUserTz ?? CarbonImmutable::now($user->timezone());
        $bounds = $this->boundsFor($settings, $date);

        return $user->salaryPeriods()->firstOrCreate(
            ['start_date' => $bounds['start']->toDateString(), 'end_date' => $bounds['end']->toDateString()],
            ['name' => $this->nameFor($bounds['start'], $bounds['end']), 'period_type' => $bounds['type']]
        );
    }

    public function currentPeriod(User $user): SalaryPeriod
    {
        return $this->periodFor($user);
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
            $periods[] = $user->salaryPeriods()->firstOrCreate(
                ['start_date' => $bounds['start']->toDateString(), 'end_date' => $bounds['end']->toDateString()],
                ['name' => $this->nameFor($bounds['start'], $bounds['end']), 'period_type' => $bounds['type']]
            );
            $cursor = $bounds['start']->subDay();
        }

        return $periods;
    }

    /**
     * Financial + attendance summary for an inclusive date range.
     */
    public function summary(User $user, string $from, string $to): array
    {
        $settings = $this->salary->settings($user);
        $configured = $settings->isConfigured();

        $records = $user->attendanceRecords()->betweenDates($from, $to)->get();
        $adjustments = $user->salaryAdjustments()->whereBetween('adjustment_date', [$from, $to])->get();
        $expenses = (float) $user->expenses()->whereBetween('expense_date', [$from, $to])->sum('amount');

        $regular = Money::sum($records->pluck('regular_amount'));
        $overtimePay = Money::sum($records->pluck('overtime_amount'));
        $salary = Money::sum($records->pluck('salary_amount'));

        $income = Money::sum($adjustments->filter(fn (SalaryAdjustment $a) => $a->isIncome())->pluck('amount'));
        $deductions = Money::sum($adjustments->filter(fn (SalaryAdjustment $a) => ! $a->isIncome())->pluck('amount'));

        $absentDays = $records->where('status', AttendanceRecord::STATUS_ABSENT)->count();
        $absenceDeduction = 0.0;
        if ($configured && $settings->deduct_absences && $absentDays > 0) {
            $absenceDeduction = Money::round(($this->salary->dailyRate($user, $settings) ?? 0) * $absentDays) ?? 0.0;
        }

        $totalIncome = Money::round($salary + $income) ?? 0.0;
        $totalDeductions = Money::round($deductions + $absenceDeduction) ?? 0.0;
        $remaining = Money::round($totalIncome - $totalDeductions - $expenses) ?? 0.0;

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
            'remaining' => $configured ? $remaining : Money::round($income - $deductions - $expenses),
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
