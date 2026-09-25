<?php

namespace App\Providers;

use App\Models\AttendanceRecord;
use App\Models\LeaveRecord;
use App\Models\SalaryAdjustment;
use App\Models\SalarySetting;
use App\Models\WorkSchedule;
use App\Support\MoneyVersion;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Login / register: 10 attempts per minute per IP + email.
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(10)->by(strtolower((string) $request->input('email')).'|'.$request->ip());
        });

        // Time in / time out: generous but bounded, per user.
        RateLimiter::for('attendance', function (Request $request) {
            return Limit::perMinute(30)->by($request->user()?->id ?: $request->ip());
        });

        // Anything that changes a past salary invalidates the cached "salary received" of wallets.
        foreach ([AttendanceRecord::class, LeaveRecord::class, SalaryAdjustment::class, SalarySetting::class, WorkSchedule::class] as $model) {
            $model::saved(fn ($m) => MoneyVersion::bump($m->user_id));
            $model::deleted(fn ($m) => MoneyVersion::bump($m->user_id));
        }
    }
}
