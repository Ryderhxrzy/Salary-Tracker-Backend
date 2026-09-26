<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\RecurringExpense;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Recurring bills. Each active schedule has a `next_date`; when that day arrives an
 * expense is recorded from the chosen account (auto-pay) or a reminder is sent,
 * and the schedule moves on. Runs from the daily console schedule and, as a
 * fallback, whenever the user opens the dashboard.
 */
class RecurringExpenseService
{
    public function __construct(protected ExpenseService $expenses, protected AppNotificationService $notifications) {}

    public function list(User $user): Collection
    {
        return $user->recurringExpenses()->with(['category', 'wallet'])->orderBy('is_active', 'desc')->orderBy('next_date')->orderBy('id')->get();
    }

    public function create(User $user, array $data): RecurringExpense
    {
        $data = $this->withSchedule($user, $data);
        /** @var RecurringExpense $item */
        $item = $user->recurringExpenses()->create($data);

        // Fresh, so database defaults (auto_pay, remind…) are part of the answer.
        return $item->fresh(['category', 'wallet']);
    }

    public function update(RecurringExpense $item, array $data): RecurringExpense
    {
        $data = $this->withSchedule($item->user, array_merge($item->only(['frequency', 'day_of_month', 'second_day_of_month', 'weekday', 'month_of_year', 'start_date']), $data), $item);
        $item->fill($data)->save();

        return $item->fresh(['category', 'wallet']);
    }

    public function delete(RecurringExpense $item): void
    {
        $item->delete();
    }

    /** Record this bill now (an extra payment or an early one) and move the schedule on if it was due. */
    public function payNow(RecurringExpense $item, ?string $date = null): Expense
    {
        $today = CarbonImmutable::now($item->user->timezone())->toDateString();
        $expense = $this->record($item, $date ?? $today);
        if ($item->next_date->toDateString() <= ($date ?? $today)) {
            $this->advance($item, CarbonImmutable::parse($date ?? $today, $item->user->timezone()));
        }

        return $expense;
    }

    /**
     * Pay or remind about everything that is due for this user (or everyone when null).
     * Safe to call many times a day: a schedule only moves forward.
     *
     * @return int the number of expenses recorded
     */
    public function runDue(?User $user = null): int
    {
        $recorded = 0;
        $query = RecurringExpense::query()->where('is_active', true)->with(['user.profile', 'wallet', 'category']);
        if ($user) {
            $query->where('user_id', $user->id);
        }
        $query->orderBy('id')->chunkById(100, function (Collection $items) use (&$recorded) {
            foreach ($items as $item) {
                $recorded += $this->process($item);
            }
        });

        return $recorded;
    }

    private function process(RecurringExpense $item): int
    {
        $owner = $item->user;
        if (! $owner) {
            return 0;
        }
        $tz = $owner->timezone();
        $today = CarbonImmutable::now($tz)->startOfDay();
        $recorded = 0;

        // Reminder a day (or more) ahead, once per due date.
        if ($item->remind && $item->remind_days_before > 0) {
            $remindOn = $item->next_date->copy()->subDays($item->remind_days_before)->toDateString();
            if ($remindOn === $today->toDateString() && ! $this->alreadyNotified($item, 'expense_due_soon', $item->next_date->toDateString())) {
                $when = $item->remind_days_before === 1 ? 'tomorrow' : "in {$item->remind_days_before} days";
                $this->notifications->notify($owner, 'expense_due_soon', "{$item->description} is due {$when}", '₱'.number_format((float) $item->amount, 2).($item->wallet ? " from {$item->wallet->name}" : '').' on '.$item->next_date->format('M j').($item->auto_pay ? '. It will be recorded automatically.' : '.'), ['recurring_expense_id' => $item->id, 'due_date' => $item->next_date->toDateString()]);
            }
        }

        // Everything due up to today: record (auto-pay) or remind, then move on.
        $guard = 0;
        while ($item->is_active && $item->next_date->toDateString() <= $today->toDateString() && $guard++ < 60) {
            $due = $item->next_date->toDateString();
            if ($item->auto_pay) {
                $this->record($item, $due);
                $recorded++;
                $this->notifications->notify($owner, 'expense_paid', "{$item->description} recorded", '₱'.number_format((float) $item->amount, 2).' was taken from '.($item->wallet?->name ?? 'your account').' on '.CarbonImmutable::parse($due)->format('M j').'.', ['recurring_expense_id' => $item->id, 'date' => $due]);
            } elseif (! $this->alreadyNotified($item, 'expense_due', $due)) {
                $this->notifications->notify($owner, 'expense_due', "{$item->description} is due today", 'Pay ₱'.number_format((float) $item->amount, 2).($item->wallet ? " from {$item->wallet->name}" : '').' and record it in Expenses.', ['recurring_expense_id' => $item->id, 'due_date' => $due]);
            }
            $this->advance($item, CarbonImmutable::parse($due, $tz));
        }

        return $recorded;
    }

    private function record(RecurringExpense $item, string $date): Expense
    {
        return DB::transaction(function () use ($item, $date) {
            $expense = $this->expenses->create($item->user, [
                'amount' => Money::round((float) $item->amount),
                'expense_category_id' => $item->expense_category_id,
                'description' => $item->description,
                'expense_date' => $date,
                'wallet_id' => $item->wallet_id,
                'payment_method' => $item->wallet?->type ?? 'cash',
                'notes' => $item->notes,
            ]);
            $expense->forceFill(['recurring_expense_id' => $item->id])->save();
            $item->forceFill(['last_paid_date' => $date])->save();

            return $expense;
        });
    }

    /** Move `next_date` to the due date after `$after`; stop the schedule past its end date. */
    private function advance(RecurringExpense $item, CarbonImmutable $after): void
    {
        $next = $item->dueAfter($after);
        if ($item->end_date && $next->toDateString() > $item->end_date->toDateString()) {
            $item->forceFill(['next_date' => $next->toDateString(), 'is_active' => false])->save();

            return;
        }
        $item->forceFill(['next_date' => $next->toDateString()])->save();
    }

    private function alreadyNotified(RecurringExpense $item, string $type, string $dueDate): bool
    {
        return $item->user->appNotifications()->where('type', $type)->where('data->recurring_expense_id', $item->id)->where('data->due_date', $dueDate)->exists();
    }

    /** Fill in the schedule days from the frequency and compute the first due date. */
    private function withSchedule(User $user, array $data, ?RecurringExpense $existing = null): array
    {
        $tz = $user->timezone();
        $start = CarbonImmutable::parse($data['start_date'] ?? $existing?->start_date?->toDateString() ?? CarbonImmutable::now($tz)->toDateString(), $tz);
        $data['start_date'] = $start->toDateString();
        $frequency = $data['frequency'] ?? 'monthly';
        $data['frequency'] = $frequency;
        $data['day_of_month'] = $frequency === 'weekly' ? null : (int) ($data['day_of_month'] ?? $start->day);
        $data['second_day_of_month'] = $frequency === 'semi_monthly' ? (int) ($data['second_day_of_month'] ?? 30) : null;
        $data['weekday'] = $frequency === 'weekly' ? (int) ($data['weekday'] ?? $start->dayOfWeek) : null;
        $data['month_of_year'] = $frequency === 'yearly' ? (int) ($data['month_of_year'] ?? $start->month) : null;

        $probe = new RecurringExpense(array_merge($existing?->getAttributes() ?? [], $data));
        $probe->start_date = $start;
        // Days already paid stay paid: the first due date is never before the last payment or today.
        $floor = $start;
        $today = CarbonImmutable::now($tz)->startOfDay();
        if ($existing?->last_paid_date && $existing->last_paid_date->greaterThan($floor)) {
            $floor = CarbonImmutable::parse($existing->last_paid_date->toDateString(), $tz)->addDay();
        }
        $data['next_date'] = $probe->dueOnOrAfter($floor->greaterThan($today) ? $floor : $today)->toDateString();
        if (! empty($data['end_date']) && $data['next_date'] > $data['end_date']) {
            $data['is_active'] = false;
        }

        return $data;
    }
}
