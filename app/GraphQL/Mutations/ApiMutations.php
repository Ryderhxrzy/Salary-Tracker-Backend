<?php

namespace App\GraphQL\Mutations;

use App\Exceptions\ApiException;
use App\GraphQL\Support\ResolvesApi;
use App\Http\Requests\AttendanceActionRequest;
use App\Http\Requests\ManualAttendanceRequest;
use App\Http\Requests\StoreDeviceTokenRequest;
use App\Http\Requests\StoreExpenseCategoryRequest;
use App\Http\Requests\StoreExpenseRequest;
use App\Http\Requests\StoreIncomeRequest;
use App\Http\Requests\StoreLeaveRecordRequest;
use App\Http\Requests\StoreLoanPaymentRequest;
use App\Http\Requests\StoreLoanRequest;
use App\Http\Requests\StoreSalaryAdjustmentRequest;
use App\Http\Requests\StoreSavingsGoalRequest;
use App\Http\Requests\StoreSavingsTransactionRequest;
use App\Http\Requests\StoreWalletRequest;
use App\Http\Requests\UpdateNotificationSettingRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Requests\UpdateSalarySettingRequest;
use App\Http\Requests\UpdateWorkScheduleRequest;
use App\Http\Resources\AttendanceRecordResource;
use App\Http\Resources\DeviceTokenResource;
use App\Http\Resources\ExpenseCategoryResource;
use App\Http\Resources\ExpenseResource;
use App\Http\Resources\IncomeResource;
use App\Http\Resources\LeaveRecordResource;
use App\Http\Resources\LoanPaymentResource;
use App\Http\Resources\LoanResource;
use App\Http\Resources\NotificationSettingResource;
use App\Http\Resources\ProfileResource;
use App\Http\Resources\SalaryAdjustmentResource;
use App\Http\Resources\SalarySettingResource;
use App\Http\Resources\SavingsGoalResource;
use App\Http\Resources\SavingsTransactionResource;
use App\Http\Resources\WalletResource;
use App\Http\Resources\WorkScheduleResource;
use App\Models\AttendanceRecord;
use App\Models\DeviceToken;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Income;
use App\Models\LeaveRecord;
use App\Models\Loan;
use App\Models\LoanPayment;
use App\Models\SalaryAdjustment;
use App\Models\SavingsGoal;
use App\Models\SavingsTransaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\AttendanceService;
use App\Services\ExpenseService;
use App\Services\LoanService;
use App\Services\NotificationService;
use App\Services\SalaryService;
use App\Services\SavingsService;
use App\Services\WalletService;
use App\Services\WorkScheduleService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/** Write side of the GraphQL API. Each mutation mirrors one REST endpoint. */
class ApiMutations
{
    use ResolvesApi;

    public function __construct(
        protected AttendanceService $attendance,
        protected NotificationService $notifications,
        protected SalaryService $salary,
        protected WorkScheduleService $schedules,
        protected ExpenseService $expenses,
        protected SavingsService $savings,
        protected WalletService $wallets,
        protected LoanService $loans,
    ) {}

    // ------------------------------------------------------------------ profile / settings

    public function updateProfile($root, array $args, GraphQLContext $context): array
    {
        $user = $this->user($context);
        $input = $this->validate($args['input'], UpdateProfileRequest::rulesFor());
        $profile = $user->profile()->firstOrCreate([], ['full_name' => $user->name]);
        $profile->fill($input)->save();
        if (! empty($input['full_name'])) {
            $user->forceFill(['name' => $input['full_name']])->save();
        }

        return $this->normalize(new ProfileResource($profile->fresh()));
    }

    public function updateSalarySettings($root, array $args, GraphQLContext $context): array
    {
        $user = $this->user($context);
        $input = $this->validate($args['input'], UpdateSalarySettingRequest::rulesFor(), [UpdateSalarySettingRequest::rateCheck($args['input'])]);
        $settings = $this->salary->settings($user);
        $settings->fill($input)->save();
        $user->setRelation('salarySetting', $settings->fresh());

        $today = CarbonImmutable::now($user->timezone());
        $this->attendance->recalculateRange($user, $today->subDays(90)->toDateString(), $today->addDay()->toDateString());

        return $this->normalize([
            'settings' => new SalarySettingResource($settings->fresh()),
            'rates' => $this->salary->rates($user),
        ]);
    }

    public function updateWorkSchedule($root, array $args, GraphQLContext $context): array
    {
        $input = $this->validate(['days' => $args['days']], UpdateWorkScheduleRequest::rulesFor());

        return $this->normalize(WorkScheduleResource::collection($this->schedules->update($this->user($context), $input['days'])));
    }

    public function updateNotificationSettings($root, array $args, GraphQLContext $context): array
    {
        $input = $this->validate($args['input'], UpdateNotificationSettingRequest::rulesFor());
        $settings = $this->notifications->settings($this->user($context));
        $settings->fill($input)->save();

        return $this->normalize(new NotificationSettingResource($settings->fresh()));
    }

    // ------------------------------------------------------------------ attendance

    public function timeIn($root, array $args, GraphQLContext $context): array
    {
        return $this->attendanceAction($this->user($context), 'timeIn', $args['input'] ?? []);
    }

    public function timeOut($root, array $args, GraphQLContext $context): array
    {
        return $this->attendanceAction($this->user($context), 'timeOut', $args['input'] ?? []);
    }

    protected function attendanceAction(User $user, string $method, array $input): array
    {
        $input = $this->validate($input, AttendanceActionRequest::rulesFor());
        $result = $this->attendance->{$method}($user, [
            'idempotency_key' => $input['idempotency_key'] ?? null,
            'occurred_at' => $input['occurred_at'] ?? null,
            'source' => $input['source'] ?? 'app',
            'notes' => $input['notes'] ?? null,
        ]);
        $state = $this->attendance->todayState($user);

        return $this->normalize([
            'attendance' => new AttendanceRecordResource($result['record']),
            'already_recorded' => $result['already_recorded'],
            'state' => $state['state'],
            'notification' => $this->notifications->plan($user, $state),
        ]);
    }

    public function saveManualAttendance($root, array $args, GraphQLContext $context): array
    {
        $input = $this->validate($args['input'], ManualAttendanceRequest::rulesFor(false));
        $record = $this->attendance->saveManual($this->user($context), $this->only($input, ManualAttendanceRequest::FIELDS));

        return $this->normalize(new AttendanceRecordResource($record));
    }

    public function updateAttendance($root, array $args, GraphQLContext $context): array
    {
        $user = $this->user($context);
        /** @var AttendanceRecord $record */
        $record = $this->owned($user, 'attendanceRecords', (int) $args['id']);
        $this->authorize('update', $record);
        $input = $this->validate($args['input'], ManualAttendanceRequest::rulesFor(true));
        $record = $this->attendance->saveManual($user, $this->only($input, ManualAttendanceRequest::FIELDS), $record);

        return $this->normalize(new AttendanceRecordResource($record));
    }

    public function deleteAttendance($root, array $args, GraphQLContext $context): array
    {
        /** @var AttendanceRecord $record */
        $record = $this->owned($this->user($context), 'attendanceRecords', (int) $args['id']);
        $this->authorize('delete', $record);
        $this->attendance->delete($record);

        return $this->ok('Attendance deleted.');
    }

    // ------------------------------------------------------------------ leave

    public function createLeave($root, array $args, GraphQLContext $context): array
    {
        $user = $this->user($context);
        $data = $this->validate($args['input'], StoreLeaveRecordRequest::rulesFor(false));
        $leave = DB::transaction(function () use ($user, $data) {
            $leave = $user->leaveRecords()->updateOrCreate(['leave_date' => $data['leave_date']], $data);
            $this->syncLeaveAttendance($user, $leave);

            return $leave;
        });

        return $this->normalize(new LeaveRecordResource($leave));
    }

    public function updateLeave($root, array $args, GraphQLContext $context): array
    {
        $user = $this->user($context);
        /** @var LeaveRecord $leave */
        $leave = $this->owned($user, 'leaveRecords', (int) $args['id']);
        $this->authorize('update', $leave);
        $leave->fill($this->validate($args['input'], StoreLeaveRecordRequest::rulesFor(true)))->save();
        $this->syncLeaveAttendance($user, $leave->fresh());

        return $this->normalize(new LeaveRecordResource($leave->fresh()));
    }

    public function deleteLeave($root, array $args, GraphQLContext $context): array
    {
        $user = $this->user($context);
        /** @var LeaveRecord $leave */
        $leave = $this->owned($user, 'leaveRecords', (int) $args['id']);
        $this->authorize('delete', $leave);
        DB::transaction(function () use ($user, $leave) {
            $record = $user->attendanceRecords()->whereDate('work_date', $leave->leave_date->toDateString())->first();
            if ($record && $record->time_in === null) {
                $record->delete();
            }
            $leave->delete();
        });

        return $this->ok('Leave removed.');
    }

    /** Mirror the leave onto the attendance record for that day (unless the user actually worked). */
    protected function syncLeaveAttendance(User $user, LeaveRecord $leave): void
    {
        $record = $user->attendanceRecords()->whereDate('work_date', $leave->leave_date->toDateString())->first();
        if ($record && $record->time_in !== null) {
            return;
        }
        $this->attendance->saveManual($user, [
            'work_date' => $leave->leave_date->toDateString(),
            'status' => $leave->attendanceStatus(),
            'notes' => $leave->notes,
        ], $record);
    }

    // ------------------------------------------------------------------ salary adjustments

    public function createAdjustment($root, array $args, GraphQLContext $context): array
    {
        $item = $this->user($context)->salaryAdjustments()->create($this->validate($args['input'], StoreSalaryAdjustmentRequest::rulesFor(false)));

        return $this->normalize(new SalaryAdjustmentResource($item));
    }

    public function updateAdjustment($root, array $args, GraphQLContext $context): array
    {
        /** @var SalaryAdjustment $item */
        $item = $this->owned($this->user($context), 'salaryAdjustments', (int) $args['id']);
        $this->authorize('update', $item);
        $item->fill($this->validate($args['input'], StoreSalaryAdjustmentRequest::rulesFor(true)))->save();

        return $this->normalize(new SalaryAdjustmentResource($item->fresh()));
    }

    public function deleteAdjustment($root, array $args, GraphQLContext $context): array
    {
        /** @var SalaryAdjustment $item */
        $item = $this->owned($this->user($context), 'salaryAdjustments', (int) $args['id']);
        $this->authorize('delete', $item);
        $item->delete();

        return $this->ok('Adjustment deleted.');
    }

    // ------------------------------------------------------------------ expenses

    public function createExpense($root, array $args, GraphQLContext $context): array
    {
        $user = $this->user($context);
        $expense = $this->expenses->create($user, $this->validate($args['input'], StoreExpenseRequest::rulesFor($user, false)));

        return $this->normalize(new ExpenseResource($expense));
    }

    public function updateExpense($root, array $args, GraphQLContext $context): array
    {
        $user = $this->user($context);
        /** @var Expense $expense */
        $expense = $this->owned($user, 'expenses', (int) $args['id']);
        $this->authorize('update', $expense);
        $expense = $this->expenses->update($expense, $this->validate($args['input'], StoreExpenseRequest::rulesFor($user, true)));

        return $this->normalize(new ExpenseResource($expense));
    }

    public function deleteExpense($root, array $args, GraphQLContext $context): array
    {
        /** @var Expense $expense */
        $expense = $this->owned($this->user($context), 'expenses', (int) $args['id']);
        $this->authorize('delete', $expense);
        $this->expenses->delete($expense);

        return $this->ok('Expense deleted.');
    }

    public function createExpenseCategory($root, array $args, GraphQLContext $context): array
    {
        $user = $this->user($context);
        $category = $user->expenseCategories()->create($this->validate($args['input'], StoreExpenseCategoryRequest::rulesFor($user)) + ['is_default' => false]);

        return $this->normalize(new ExpenseCategoryResource($category));
    }

    public function updateExpenseCategory($root, array $args, GraphQLContext $context): array
    {
        $user = $this->user($context);
        /** @var ExpenseCategory $category */
        $category = $this->owned($user, 'expenseCategories', (int) $args['id']);
        $this->authorize('update', $category);
        $category->fill($this->validate($args['input'], StoreExpenseCategoryRequest::rulesFor($user, $category->id)))->save();

        return $this->normalize(new ExpenseCategoryResource($category->fresh()));
    }

    public function deleteExpenseCategory($root, array $args, GraphQLContext $context): array
    {
        /** @var ExpenseCategory $category */
        $category = $this->owned($this->user($context), 'expenseCategories', (int) $args['id']);
        $this->authorize('delete', $category);
        $category->delete();

        return $this->ok('Category deleted.');
    }

    // ------------------------------------------------------------------ goals

    public function createGoal($root, array $args, GraphQLContext $context): array
    {
        $goal = $this->user($context)->savingsGoals()->create($this->validate($args['input'], StoreSavingsGoalRequest::rulesFor($this->user($context), false)));

        return $this->normalize(new SavingsGoalResource($goal));
    }

    public function updateGoal($root, array $args, GraphQLContext $context): array
    {
        /** @var SavingsGoal $goal */
        $goal = $this->owned($this->user($context), 'savingsGoals', (int) $args['id']);
        $this->authorize('update', $goal);
        $goal->fill($this->validate($args['input'], StoreSavingsGoalRequest::rulesFor($this->user($context), true)));
        if ($goal->type !== 'spending_limit' && (float) $goal->current_amount >= (float) $goal->target_amount) {
            $goal->is_completed = true;
        }
        $goal->save();

        return $this->normalize(new SavingsGoalResource($goal->fresh()));
    }

    public function deleteGoal($root, array $args, GraphQLContext $context): array
    {
        /** @var SavingsGoal $goal */
        $goal = $this->owned($this->user($context), 'savingsGoals', (int) $args['id']);
        $this->authorize('delete', $goal);
        $goal->delete();

        return $this->ok('Goal deleted.');
    }

    // ------------------------------------------------------------------ savings / wallets

    public function createSavingsTransaction($root, array $args, GraphQLContext $context): array
    {
        $user = $this->user($context);
        $transaction = $this->savings->create($user, $this->validate($args['input'], StoreSavingsTransactionRequest::rulesFor($user, false)));

        return $this->normalize(new SavingsTransactionResource($transaction));
    }

    public function updateSavingsTransaction($root, array $args, GraphQLContext $context): array
    {
        $user = $this->user($context);
        /** @var SavingsTransaction $transaction */
        $transaction = $this->owned($user, 'savingsTransactions', (int) $args['id']);
        $this->authorize('update', $transaction);
        $transaction = $this->savings->update($transaction, $this->validate($args['input'], StoreSavingsTransactionRequest::rulesFor($user, true)));

        return $this->normalize(new SavingsTransactionResource($transaction));
    }

    public function deleteSavingsTransaction($root, array $args, GraphQLContext $context): array
    {
        /** @var SavingsTransaction $transaction */
        $transaction = $this->owned($this->user($context), 'savingsTransactions', (int) $args['id']);
        $this->authorize('delete', $transaction);
        $this->savings->delete($transaction);

        return $this->ok('Savings entry removed.');
    }

    public function createWallet($root, array $args, GraphQLContext $context): array
    {
        $user = $this->user($context);
        $wallet = $this->wallets->create($user, $this->validate($args['input'], StoreWalletRequest::rulesFor(false)));

        return $this->normalize(new WalletResource($wallet));
    }

    public function updateWallet($root, array $args, GraphQLContext $context): array
    {
        $user = $this->user($context);
        /** @var Wallet $wallet */
        $wallet = $this->owned($user, 'wallets', (int) $args['id']);
        $this->authorize('update', $wallet);
        $wallet = $this->wallets->update($user, $wallet, $this->validate($args['input'], StoreWalletRequest::rulesFor(true)));

        return $this->normalize(new WalletResource($wallet));
    }

    public function deleteWallet($root, array $args, GraphQLContext $context): array
    {
        $user = $this->user($context);
        /** @var Wallet $wallet */
        $wallet = $this->owned($user, 'wallets', (int) $args['id']);
        $this->authorize('delete', $wallet);
        if ($user->wallets()->count() <= 1) {
            throw ApiException::unprocessable('You need at least one wallet.', [], 'last_wallet');
        }
        $this->wallets->delete($user, $wallet);

        return $this->ok('Wallet deleted.');
    }

    // ------------------------------------------------------------------ loans

    public function createLoan($root, array $args, GraphQLContext $context): array
    {
        $user = $this->user($context);

        return $this->normalize(new LoanResource($this->loans->create($user, $this->validate($args['input'], StoreLoanRequest::rulesFor($user, false)))));
    }

    public function updateLoan($root, array $args, GraphQLContext $context): array
    {
        $user = $this->user($context);
        /** @var Loan $loan */
        $loan = $this->owned($user, 'loans', (int) $args['id']);
        $this->authorize('update', $loan);

        return $this->normalize(new LoanResource($this->loans->update($user, $loan, $this->validate($args['input'], StoreLoanRequest::rulesFor($user, true)))));
    }

    public function deleteLoan($root, array $args, GraphQLContext $context): array
    {
        /** @var Loan $loan */
        $loan = $this->owned($this->user($context), 'loans', (int) $args['id']);
        $this->authorize('delete', $loan);
        $this->loans->delete($loan);

        return $this->ok('Loan deleted.');
    }

    public function createLoanPayment($root, array $args, GraphQLContext $context): array
    {
        $user = $this->user($context);
        /** @var Loan $loan */
        $loan = $this->owned($user, 'loans', (int) $args['loan_id']);
        $this->authorize('update', $loan);
        $payment = $this->loans->addPayment($user, $loan, $this->validate($args['input'], StoreLoanPaymentRequest::rulesFor($user, false)));

        return $this->normalize(new LoanPaymentResource($payment));
    }

    public function updateLoanPayment($root, array $args, GraphQLContext $context): array
    {
        $user = $this->user($context);
        /** @var LoanPayment $payment */
        $payment = $this->owned($user, 'loanPayments', (int) $args['id']);
        $this->authorize('update', $payment);

        return $this->normalize(new LoanPaymentResource($this->loans->updatePayment($payment, $this->validate($args['input'], StoreLoanPaymentRequest::rulesFor($user, true)))));
    }

    public function deleteLoanPayment($root, array $args, GraphQLContext $context): array
    {
        /** @var LoanPayment $payment */
        $payment = $this->owned($this->user($context), 'loanPayments', (int) $args['id']);
        $this->authorize('delete', $payment);
        $this->loans->deletePayment($payment);

        return $this->ok('Payment removed.');
    }

    // ------------------------------------------------------------------ other income

    public function createIncome($root, array $args, GraphQLContext $context): array
    {
        $user = $this->user($context);
        $data = $this->validate($args['input'], StoreIncomeRequest::rulesFor($user, false));
        $data['wallet_id'] = $data['wallet_id'] ?? $this->wallets->forMethod($user, null)?->id;

        return $this->normalize(new IncomeResource($user->incomes()->create($data)->load('wallet')));
    }

    public function updateIncome($root, array $args, GraphQLContext $context): array
    {
        $user = $this->user($context);
        /** @var Income $income */
        $income = $this->owned($user, 'incomes', (int) $args['id']);
        $this->authorize('update', $income);
        $income->fill($this->validate($args['input'], StoreIncomeRequest::rulesFor($user, true)))->save();

        return $this->normalize(new IncomeResource($income->fresh('wallet')));
    }

    public function deleteIncome($root, array $args, GraphQLContext $context): array
    {
        /** @var Income $income */
        $income = $this->owned($this->user($context), 'incomes', (int) $args['id']);
        $this->authorize('delete', $income);
        $income->delete();

        return $this->ok('Income deleted.');
    }

    // ------------------------------------------------------------------ devices

    public function registerDeviceToken($root, array $args, GraphQLContext $context): array
    {
        $user = $this->user($context);
        $input = $this->validate($args['input'], StoreDeviceTokenRequest::rulesFor());
        DeviceToken::where('token', $input['token'])->where('user_id', '!=', $user->id)->delete();
        $device = $user->deviceTokens()->updateOrCreate(
            ['token' => $input['token']],
            ['platform' => $input['platform'] ?? 'android', 'device_name' => $input['device_name'] ?? null, 'last_seen_at' => now()]
        );

        return $this->normalize(new DeviceTokenResource($device));
    }

    public function deleteDeviceToken($root, array $args, GraphQLContext $context): array
    {
        /** @var DeviceToken $device */
        $device = $this->owned($this->user($context), 'deviceTokens', (int) $args['id']);
        $this->authorize('delete', $device);
        $device->delete();

        return $this->ok('Device removed.');
    }

    // ------------------------------------------------------------------ helpers

    protected function ok(string $message): array
    {
        return ['success' => true, 'message' => $message];
    }

    /** Keep only the given keys (present ones, including explicit nulls). */
    protected function only(array $input, array $keys): array
    {
        return array_intersect_key($input, array_flip($keys));
    }
}
