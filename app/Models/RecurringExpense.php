<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A bill paid again and again (rent, Netflix, load…): how much, on which days and
 * which account it is taken from. On each due date an expense is recorded
 * (auto-pay) or a reminder is sent, and `next_date` moves to the following one.
 */
#[Fillable([
    'expense_category_id', 'wallet_id', 'amount', 'description', 'frequency',
    'day_of_month', 'second_day_of_month', 'weekday', 'month_of_year',
    'start_date', 'next_date', 'end_date', 'last_paid_date',
    'auto_pay', 'remind', 'remind_days_before', 'is_active', 'notes',
])]
class RecurringExpense extends Model
{
    use SoftDeletes;

    public const FREQUENCIES = ['weekly', 'monthly', 'semi_monthly', 'yearly'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'start_date' => 'date:Y-m-d',
            'next_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'last_paid_date' => 'date:Y-m-d',
            'auto_pay' => 'boolean',
            'remind' => 'boolean',
            'is_active' => 'boolean',
            'remind_days_before' => 'integer',
            'day_of_month' => 'integer',
            'second_day_of_month' => 'integer',
            'weekday' => 'integer',
            'month_of_year' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    /** The account the payment is taken from. */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class)->withTrashed();
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    /** The first due date on or after `$from` for this schedule. */
    public function dueOnOrAfter(CarbonImmutable $from): CarbonImmutable
    {
        $from = $from->startOfDay();
        switch ($this->frequency) {
            case 'weekly':
                $weekday = $this->weekday ?? $this->start_date->dayOfWeek;
                $diff = ($weekday - $from->dayOfWeek + 7) % 7;

                return $from->addDays($diff);
            case 'semi_monthly': {
                $first = max(1, min(28, $this->day_of_month ?? 15));
                $second = $this->second_day_of_month ?? 30;
                $candidates = [];
                foreach ([$from->subMonth(), $from, $from->addMonth()] as $month) {
                    $candidates[] = self::onDay($month, $first);
                    $candidates[] = self::onDay($month, $second);
                }
                sort($candidates);
                foreach ($candidates as $candidate) {
                    if ($candidate->greaterThanOrEqualTo($from)) {
                        return $candidate;
                    }
                }

                return end($candidates);
            }
            case 'yearly': {
                $month = $this->month_of_year ?? $this->start_date->month;
                $day = $this->day_of_month ?? $this->start_date->day;
                $candidate = self::onDay($from->setMonth($month), $day);

                return $candidate->greaterThanOrEqualTo($from) ? $candidate : self::onDay($from->addYear()->setMonth($month), $day);
            }
            case 'monthly':
            default: {
                $day = $this->day_of_month ?? $this->start_date->day;
                $candidate = self::onDay($from, $day);

                return $candidate->greaterThanOrEqualTo($from) ? $candidate : self::onDay($from->addMonth(), $day);
            }
        }
    }

    /** The due date after `$date`. */
    public function dueAfter(CarbonImmutable $date): CarbonImmutable
    {
        return $this->dueOnOrAfter($date->addDay());
    }

    /** Day `$day` of the month of `$month`, clamped to that month's length (the 30th in February = the 28th/29th). */
    private static function onDay(CarbonImmutable $month, int $day): CarbonImmutable
    {
        return $month->startOfMonth()->setDay(min($day, $month->daysInMonth));
    }
}
