<?php

use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DeviceTokenController;
use App\Http\Controllers\Api\ExpenseCategoryController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\IncomeController;
use App\Http\Controllers\Api\LeaveRecordController;
use App\Http\Controllers\Api\LoanController;
use App\Http\Controllers\Api\NotificationSettingController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\SalaryAdjustmentController;
use App\Http\Controllers\Api\SalaryController;
use App\Http\Controllers\Api\SalaryReceiptController;
use App\Http\Controllers\Api\SalarySettingController;
use App\Http\Controllers\Api\SavingsController;
use App\Http\Controllers\Api\SavingsGoalController;
use App\Http\Controllers\Api\StatisticsController;
use App\Http\Controllers\Api\WalletController;
use App\Http\Controllers\Api\WalletTransferController;
use App\Http\Controllers\Api\WorkScheduleController;
use Illuminate\Support\Facades\Route;

// ---------------------------------------------------------------------------
// Public
// ---------------------------------------------------------------------------
Route::prefix('auth')->middleware('throttle:auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
});

// Profile pictures: random file names, so the URL itself is the secret (the app's image view sends no token).
Route::get('avatars/{file}', [ProfileController::class, 'avatar'])->where('file', '[A-Za-z0-9_-]+\.(jpg|jpeg|png|webp)');

// ---------------------------------------------------------------------------
// Authenticated (Sanctum bearer tokens)
// ---------------------------------------------------------------------------
Route::middleware('auth:sanctum')->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::get('user', [AuthController::class, 'me']);

    Route::get('dashboard', [DashboardController::class, 'index']);

    Route::get('profile', [ProfileController::class, 'show']);
    Route::put('profile', [ProfileController::class, 'update']);
    Route::post('profile/avatar', [ProfileController::class, 'storeAvatar']);
    Route::delete('profile/avatar', [ProfileController::class, 'destroyAvatar']);

    Route::get('salary-settings', [SalarySettingController::class, 'show']);
    Route::put('salary-settings', [SalarySettingController::class, 'update']);

    Route::get('work-schedule', [WorkScheduleController::class, 'show']);
    Route::put('work-schedule', [WorkScheduleController::class, 'update']);

    Route::get('notification-settings', [NotificationSettingController::class, 'show']);
    Route::put('notification-settings', [NotificationSettingController::class, 'update']);
    Route::get('notifications/plan', [NotificationSettingController::class, 'plan']);

    Route::prefix('attendance')->group(function () {
        Route::get('/', [AttendanceController::class, 'index']);
        Route::get('today', [AttendanceController::class, 'today']);
        Route::post('time-in', [AttendanceController::class, 'timeIn'])->middleware('throttle:attendance');
        Route::post('time-out', [AttendanceController::class, 'timeOut'])->middleware('throttle:attendance');
        Route::post('manual', [AttendanceController::class, 'manual']);
        Route::get('{attendance}', [AttendanceController::class, 'show'])->whereNumber('attendance');
        Route::put('{attendance}', [AttendanceController::class, 'update'])->whereNumber('attendance');
        Route::delete('{attendance}', [AttendanceController::class, 'destroy'])->whereNumber('attendance');
    });

    Route::apiResource('leave-records', LeaveRecordController::class)->only(['index', 'store', 'update', 'destroy'])->parameters(['leave-records' => 'leave']);

    Route::get('salary', [SalaryController::class, 'index']);
    Route::get('salary/periods', [SalaryController::class, 'periods']);
    Route::get('salary/summary', [SalaryController::class, 'summary']);
    Route::apiResource('salary-adjustments', SalaryAdjustmentController::class)->only(['index', 'store', 'update', 'destroy'])->parameters(['salary-adjustments' => 'adjustment']);

    Route::get('expenses/summary', [ExpenseController::class, 'summary']);
    Route::apiResource('expenses', ExpenseController::class);
    Route::apiResource('expense-categories', ExpenseCategoryController::class)->only(['index', 'store', 'update', 'destroy'])->parameters(['expense-categories' => 'category']);

    Route::get('statistics', [StatisticsController::class, 'index']);
    Route::get('calendar', [StatisticsController::class, 'calendar']);

    Route::apiResource('goals', SavingsGoalController::class)->only(['index', 'store', 'update', 'destroy'])->parameters(['goals' => 'goal']);

    // Savings (money set aside from the salary) and wallets (where the money is).
    Route::get('savings', [SavingsController::class, 'index']);
    Route::get('savings/transactions', [SavingsController::class, 'transactions']);
    Route::post('savings/transactions', [SavingsController::class, 'store']);
    Route::put('savings/transactions/{transaction}', [SavingsController::class, 'update'])->whereNumber('transaction');
    Route::delete('savings/transactions/{transaction}', [SavingsController::class, 'destroy'])->whereNumber('transaction');
    Route::apiResource('wallets', WalletController::class)->only(['index', 'store', 'update', 'destroy']);
    // "Receive salary": confirm a finished cut-off's pay arrived (credits the salary wallet) / undo.
    Route::post('salary-receipts', [SalaryReceiptController::class, 'store']);
    Route::delete('salary-receipts/{receipt}', [SalaryReceiptController::class, 'destroy'])->whereNumber('receipt');
    // Money moved from one wallet to another (cash-in, cash-out, bank withdrawal…).
    Route::apiResource('wallet-transfers', WalletTransferController::class)->only(['index', 'store', 'update', 'destroy'])->parameters(['wallet-transfers' => 'transfer']);

    // Loans: money owed (SSS, Pag-IBIG, company, personal) and money lent, with their payments.
    // Other income (side hustle, freelance…), not part of the salary.
    Route::apiResource('incomes', IncomeController::class)->only(['index', 'store', 'update', 'destroy']);

    Route::apiResource('loans', LoanController::class);
    Route::post('loans/{loan}/payments', [LoanController::class, 'storePayment'])->whereNumber('loan');
    Route::put('loan-payments/{payment}', [LoanController::class, 'updatePayment'])->whereNumber('payment');
    Route::delete('loan-payments/{payment}', [LoanController::class, 'destroyPayment'])->whereNumber('payment');

    Route::post('device-tokens', [DeviceTokenController::class, 'store']);
    Route::delete('device-tokens/{deviceToken}', [DeviceTokenController::class, 'destroy']);
});
