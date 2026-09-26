<?php

use App\Services\RecurringExpenseService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Record (or remind about) every recurring bill that is due; the dashboard also runs this per user as a fallback.
Artisan::command('recurring:run', function (RecurringExpenseService $recurring) {
    $count = $recurring->runDue();
    $this->info("Recorded {$count} recurring expense(s).");
})->purpose('Record recurring expenses that are due and send their reminders');

Schedule::command('recurring:run')->dailyAt('07:00')->timezone(config('salary_tracker.timezone'));
