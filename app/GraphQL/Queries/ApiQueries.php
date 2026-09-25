<?php

namespace App\GraphQL\Queries;

use App\Exceptions\ApiException;
use App\GraphQL\Support\ResolvesApi;
use App\Http\Resources\AttendanceRecordResource;
use App\Http\Resources\ExpenseCategoryResource;
use App\Http\Resources\ExpenseResource;
use App\Http\Resources\IncomeResource;
use App\Http\Resources\LeaveRecordResource;
use App\Http\Resources\LoanResource;
use App\Http\Resources\NotificationSettingResource;
use App\Http\Resources\ProfileResource;
use App\Http\Resources\SalaryAdjustmentResource;
use App\Http\Resources\SalaryPeriodResource;
use App\Http\Resources\SalarySettingResource;
use App\Http\Resources\SavingsGoalResource;
use App\Http\Resources\SavingsTransactionResource;
use App\Http\Resources\UserResource;
use App\Http\Resources\WalletResource;
use App\Http\Resources\WalletTransferResource;
use App\Http\Resources\WorkScheduleResource;
use App\Models\SalaryAdjustment;
use App\Models\SalaryPeriod;
use App\Services\AttendanceService;
use App\Services\DashboardService;
use App\Services\ExpenseService;
use App\Services\LoanService;
use App\Services\NotificationService;
use App\Services\SalaryPeriodService;
use App\Services\SalaryService;
use App\Services\SavingsService;
use App\Services\StatisticsService;
use App\Services\WalletService;
use App\Services\WorkScheduleService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/** Read side of the GraphQL API: one method per query, same data as the REST endpoints. */
class ApiQueries
{
    use ResolvesApi;

    public function __construct(
        protected DashboardService $dashboard,
        protected SalaryService $salary,
        protected SalaryPeriodService $periods,
        protected WorkScheduleService $schedules,
        protected NotificationService $notifications,
        protected AttendanceService $attendance,
        protected StatisticsService $statistics,
        protected ExpenseService $expenses,
        protected SavingsService $savings,
        protected WalletService $wallets,
        protected LoanService $loans,
    ) {}

    public function me($root, array $args, GraphQLContext $context): array
    {
        return $this->normalize(new UserResource($this->user($context)->load(['profile', 'salarySetting'])));
    }

    public function dashboard($root, array $args, GraphQLContext $context): array
    {
        return $this->normalize($this->dashboard->build($this->user($context)));
    }

    public function profile($root, array $args, GraphQLContext $context): array
    {
        $user = $this->user($context);

        return $this->normalize(new ProfileResource($user->profile()->firstOrCreate([], ['full_name' => $user->name])));
    }

    public function salarySettings($root, array $args, GraphQLContext $context): array
    {
        $user = $this->user($context);

        return $this->normalize([
            'settings' => new SalarySettingResource($this->salary->settings($user)),
            'rates' => $this->salary->rates($user),
        ]);
    }

    public function workSchedule($root, array $args, GraphQLContext $context): array
    {
        return $this->normalize(WorkScheduleResource::collection($this->schedules->ensureDefaults($this->user($context))));
    }

    public function notificationSettings($root, array $args, GraphQLContext $context): array
    {
        return $this->normalize(new NotificationSettingResource($this->notifications->settings($this->user($context))));
    }

    public function notificationPlan($root, array $args, GraphQLContext $context): array
    {
        return $this->normalize($this->notifications->plan($this->user($context)));
    }

    public function attendance($root, array $args, GraphQLContext $context): array
    {
        $user = $this->user($context);
        $range = $this->resolveRange($user, $args);
        $records = $user->attendanceRecords()->betweenDates($range['from'], $range['to'])->orderByDesc('work_date')->get();

        return $this->normalize(['range' => $range, 'records' => AttendanceRecordResource::collection($records)]);
    }

    public function attendanceToday($root, array $args, GraphQLContext $context): array
    {
        $user = $this->user($context);
        $state = $this->attendance->todayState($user);

        return $this->normalize([
            'state' => $state['state'],
            'date' => $state['date'],
            'attendance' => $state['record'] ? new AttendanceRecordResource($state['record']) : null,
            'notification' => $this->notifications->plan($user, $state),
        ]);
    }

    public function attendanceRecord($root, array $args, GraphQLContext $context): array
    {
        return $this->normalize(new AttendanceRecordResource($this->owned($this->user($context), 'attendanceRecords', (int) $args['id'])));
    }

    public function leaveRecords($root, array $args, GraphQLContext $context): array
    {
        $user = $this->user($context);
        $range = $this->resolveRange($user, $args);
        $records = $user->leaveRecords()->whereBetween('leave_date', [$range['from'], $range['to']])->orderByDesc('leave_date')->get();

        return $this->normalize(['range' => $range, 'records' => LeaveRecordResource::collection($records)]);
    }

    public function salary($root, array $args, GraphQLContext $context): array
    {
        $user = $this->user($context);
        $period = $this->periods->currentPeriod($user);
        $previous = $this->periods->previousPeriod($user, $period);
        $previousSummary = $this->periods->compute($user, $previous);

        return $this->normalize([
            'settings' => new SalarySettingResource($this->salary->settings($user)),
            'rates' => $this->salary->rates($user),
            'period' => new SalaryPeriodResource($period),
            'summary' => $this->periods->compute($user, $period),
            'payday' => $previousSummary['status'] === SalaryPeriodService::STATUS_COMPLETED
                ? ['period' => new SalaryPeriodResource($previous), 'summary' => $previousSummary]
                : null,
        ]);
    }

    public function salaryPeriods($root, array $args, GraphQLContext $context): array
    {
        $user = $this->user($context);
        $count = min(36, max(1, (int) ($args['count'] ?? 12)));
        $periods = collect($this->periods->recentPeriods($user, $count))->map(function (SalaryPeriod $period) use ($user) {
            $period->summary = $this->periods->compute($user, $period);

            return $period;
        });

        return $this->normalize(SalaryPeriodResource::collection($periods));
    }

    public function salarySummary($root, array $args, GraphQLContext $context): array
    {
        $user = $this->user($context);
        $range = $this->resolveRange($user, $args);

        return $this->normalize(['range' => $range, 'summary' => $this->periods->summary($user, $range['from'], $range['to'])]);
    }

    public function salaryAdjustments($root, array $args, GraphQLContext $context): array
    {
        $user = $this->user($context);
        $range = $this->resolveRange($user, $args);
        $items = $user->salaryAdjustments()->forPeriod($range['from'], $range['to'])->orderByDesc('recurring')->orderByDesc('adjustment_date')->orderByDesc('id')->get();

        return $this->normalize([
            'range' => $range,
            'income' => Money::sum($items->filter(fn (SalaryAdjustment $a) => $a->isIncome())->pluck('amount')),
            'deductions' => Money::sum($items->filter(fn (SalaryAdjustment $a) => ! $a->isIncome())->pluck('amount')),
            'adjustments' => SalaryAdjustmentResource::collection($items),
        ]);
    }

    public function expenses($root, array $args, GraphQLContext $context): array
    {
        $user = $this->user($context);
        $range = $this->resolveRange($user, $args);
        if (isset($args['page'])) {
            request()->merge(['page' => (int) $args['page']]);
        }
        $paginator = $this->expenses->list($user, [
            'from' => $range['from'],
            'to' => $range['to'],
            'category_id' => $args['category_id'] ?? null,
            'search' => $args['search'] ?? null,
            'per_page' => $args['per_page'] ?? null,
        ]);

        return $this->normalize([
            'range' => $range,
            'total' => $this->expenses->totalBetween($user, $range['from'], $range['to']),
            'expenses' => ExpenseResource::collection($paginator->items()),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function expenseSummary($root, array $args, GraphQLContext $context): array
    {
        $user = $this->user($context);
        $range = $this->resolveRange($user, $args);

        $summary = $this->normalize([
            'range' => $range,
            'total' => $this->expenses->totalBetween($user, $range['from'], $range['to']),
            'by_category' => $this->expenses->byCategory($user, $range['from'], $range['to']),
        ]);
        // A map keyed by date must stay an object even when empty ({} not []).
        $summary['by_day'] = (object) $this->expenses->byDay($user, $range['from'], $range['to']);

        return $summary;
    }

    public function expense($root, array $args, GraphQLContext $context): array
    {
        $expense = $this->owned($this->user($context), 'expenses', (int) $args['id']);

        return $this->normalize(new ExpenseResource($expense->load(['category', 'wallet'])));
    }

    public function expenseCategories($root, array $args, GraphQLContext $context): array
    {
        return $this->normalize(ExpenseCategoryResource::collection($this->expenses->ensureDefaultCategories($this->user($context))));
    }

    public function statistics($root, array $args, GraphQLContext $context): array
    {
        $user = $this->user($context);
        $range = $this->resolveRange($user, $args);

        return $this->normalize(['label' => $range['label']] + $this->statistics->build($user, $range['from'], $range['to']));
    }

    public function calendar($root, array $args, GraphQLContext $context): array
    {
        $user = $this->user($context);
        $today = CarbonImmutable::now($user->timezone());
        $year = (int) ($args['year'] ?? $today->year);
        $month = (int) ($args['month'] ?? $today->month);
        if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
            throw ApiException::unprocessable('Invalid year or month.');
        }

        return $this->normalize($this->statistics->calendar($user, $year, $month));
    }

    public function goals($root, array $args, GraphQLContext $context): array
    {
        $goals = $this->user($context)->savingsGoals()->orderBy('is_completed')->orderByDesc('id')->get();

        return $this->normalize(SavingsGoalResource::collection($goals));
    }

    public function savings($root, array $args, GraphQLContext $context): array
    {
        $user = $this->user($context);
        $range = $this->resolveRange($user, $args);

        return $this->normalize($this->savings->overview($user, $range));
    }

    public function savingsTransactions($root, array $args, GraphQLContext $context): array
    {
        $user = $this->user($context);
        $range = $this->resolveRange($user, $args);
        $query = $user->savingsTransactions()->with(['goal', 'wallet'])
            ->whereBetween('transaction_date', [$range['from'], $range['to']])
            ->orderByDesc('transaction_date')->orderByDesc('id');
        if (! empty($args['goal_id'])) {
            $query->where('savings_goal_id', (int) $args['goal_id']);
        }

        return $this->normalize(['range' => $range, 'transactions' => SavingsTransactionResource::collection($query->limit(200)->get())]);
    }

    public function wallets($root, array $args, GraphQLContext $context): array
    {
        return $this->normalize(WalletResource::collection($this->wallets->withBalances($this->user($context))));
    }

    public function loans($root, array $args, GraphQLContext $context): array
    {
        return $this->normalize(LoanResource::collection($this->loans->list($this->user($context), $args['status'] ?? 'all')));
    }

    public function loan($root, array $args, GraphQLContext $context): array
    {
        $loan = $this->loans->find($this->user($context), (int) $args['id']) ?? throw ApiException::notFound();

        return $this->normalize(new LoanResource($loan));
    }

    public function loansOverview($root, array $args, GraphQLContext $context): array
    {
        $user = $this->user($context);
        $range = $this->resolveRange($user, $args);

        return $this->normalize($this->loans->overview($user, $range['from'], $range['to']));
    }

    public function incomes($root, array $args, GraphQLContext $context): array
    {
        $user = $this->user($context);
        $range = $this->resolveRange($user, $args);
        $items = $user->incomes()->with('wallet')->whereBetween('income_date', [$range['from'], $range['to']])->orderByDesc('income_date')->orderByDesc('id')->get();

        return $this->normalize(['range' => $range, 'total' => Money::sum($items->pluck('amount')), 'incomes' => IncomeResource::collection($items)]);
    }

    public function walletTransfers($root, array $args, GraphQLContext $context): array
    {
        $user = $this->user($context);
        $range = $this->resolveRange($user, $args);
        $query = $user->walletTransfers()->with(['fromWallet', 'toWallet'])->whereBetween('transfer_date', [$range['from'], $range['to']]);
        if (! empty($args['wallet'])) {
            $walletId = (int) $args['wallet'];
            $query->where(fn ($q) => $q->where('from_wallet_id', $walletId)->orWhere('to_wallet_id', $walletId));
        }
        $items = $query->orderByDesc('transfer_date')->orderByDesc('id')->get();

        return $this->normalize([
            'range' => $range,
            'total' => Money::sum($items->pluck('amount')),
            'fees' => Money::sum($items->pluck('fee')),
            'transfers' => WalletTransferResource::collection($items),
        ]);
    }

    /** ?range=today|week|month|period|custom&from&to, validated like RangeRequest. */
    protected function resolveRange($user, array $args): array
    {
        $range = $this->validate($this->rangeArgs($args), [
            'range' => ['nullable', 'in:today,week,month,period,custom'],
            'from' => ['nullable', 'date_format:Y-m-d', 'required_if:range,custom'],
            'to' => ['nullable', 'date_format:Y-m-d', 'required_if:range,custom', 'after_or_equal:from'],
        ]);

        return $this->statistics->resolveRange($user, $range['range'] ?? 'period', $range['from'] ?? null, $range['to'] ?? null);
    }
}
