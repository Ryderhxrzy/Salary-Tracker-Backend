<?php

namespace App\Services;

use App\Models\Income;
use App\Models\Loan;
use App\Models\LoanPayment;
use App\Models\SavingsTransaction;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransfer;
use App\Support\Money;
use App\Support\MoneyVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Wallets (cash, GCash, bank...) and their computed balances:
 *
 *   balance = opening balance (as of a date)
 *           + salary received since then (paid cut-offs, only into the wallet that receives the salary)
 *           − expenses paid from the wallet since then
 *           − savings deposited from the wallet + savings withdrawn into it
 *           + loans received into it − money lent from it − loan payments made from it + repayments received into it
 *           + transfers received from another wallet − transfers sent to another wallet
 *           + money saved into goals this wallet keeps (goals_in); `available` = balance − goals kept here
 */
class WalletService
{
    public function __construct(protected SalaryPeriodService $periods) {}

    /**
     * The user's wallets, loaded once per request. A user without any account gets a
     * single "Cash" one that receives the salary; everything else is added by the user.
     *
     * @return Collection<int, Wallet>
     */
    public function ensureDefaults(User $user): Collection
    {
        if ($user->relationLoaded('wallets') && $user->wallets->isNotEmpty()) {
            return $user->wallets;
        }
        $wallets = $user->wallets()->get();
        if ($wallets->isEmpty()) {
            $user->wallets()->create([
                'name' => 'Cash', 'type' => 'cash', 'category' => 'cash', 'institution_id' => 'cash',
                'opening_balance' => 0, 'balance_as_of' => CarbonImmutable::now($user->timezone())->toDateString(),
                'receives_salary' => true, 'is_default' => true, 'sort_order' => 0,
            ]);
            $wallets = $user->wallets()->get();
        }
        // Only one account can receive the salary; older data may have several flagged.
        $salaryWallets = $wallets->where('receives_salary', true);
        if ($salaryWallets->count() > 1) {
            $keep = $salaryWallets->first();
            $user->wallets()->whereKeyNot($keep->id)->update(['receives_salary' => false]);
            $wallets = $user->wallets()->get();
        }
        $user->setRelation('wallets', $wallets);

        return $wallets;
    }

    public function create(User $user, array $data): Wallet
    {
        $this->ensureDefaults($user);
        $data['balance_as_of'] = $data['balance_as_of'] ?? CarbonImmutable::now($user->timezone())->toDateString();
        $data['type'] = $data['type'] ?? 'other';
        $data['category'] = $data['category'] ?? match ($data['type']) {
            'cash' => 'cash',
            'gcash', 'maya' => 'ewallet',
            'bank', 'card' => 'bank',
            default => 'other',
        };
        $data['sort_order'] = ((int) $user->wallets()->max('sort_order')) + 1;
        $wallet = $user->wallets()->create($data);
        $this->applyFlags($user, $wallet, $data);
        $user->unsetRelation('wallets');

        return $wallet->fresh();
    }

    public function update(User $user, Wallet $wallet, array $data): Wallet
    {
        $wallet->fill($data)->save();
        $this->applyFlags($user, $wallet, $data);
        $user->unsetRelation('wallets');

        return $wallet->fresh();
    }

    public function delete(User $user, Wallet $wallet): void
    {
        $wallet->delete();
        $user->unsetRelation('wallets');
        $remaining = $this->ensureDefaults($user);
        // Never leave the user without a default / salary wallet.
        if ($remaining->isNotEmpty() && ! $remaining->contains('is_default', true)) {
            $remaining->first()->update(['is_default' => true]);
        }
        if ($remaining->isNotEmpty() && ! $remaining->contains('receives_salary', true)) {
            $remaining->firstWhere('is_default', true)?->update(['receives_salary' => true]);
        }
        $user->unsetRelation('wallets');
    }

    /** Only one wallet is the default and only one receives the salary. */
    protected function applyFlags(User $user, Wallet $wallet, array $data): void
    {
        if (! empty($data['is_default'])) {
            $user->wallets()->whereKeyNot($wallet->id)->update(['is_default' => false]);
        }
        if (! empty($data['receives_salary'])) {
            $user->wallets()->whereKeyNot($wallet->id)->update(['receives_salary' => false]);
        }
    }

    /** The wallet an expense paid with `$method` (cash, gcash...) most likely came from. */
    public function forMethod(User $user, ?string $method): ?Wallet
    {
        $wallets = $this->ensureDefaults($user);

        return ($method ? $wallets->firstWhere('type', $method) : null)
            ?? $wallets->firstWhere('is_default', true)
            ?? $wallets->first();
    }

    /**
     * All wallets with `balance`, `salary_received`, `spent` and `saved` set on each.
     *
     * @return Collection<int, Wallet>
     */
    public function withBalances(User $user): Collection
    {
        $wallets = $this->ensureDefaults($user);
        if ($wallets->isEmpty()) {
            return $wallets;
        }
        $earliest = $wallets->min(fn (Wallet $w) => $w->balance_as_of->toDateString());

        // Expenses: only those paid from a wallet lower it ("not from a wallet" is tracked, not charged).
        $spent = [];
        $user->expenses()
            ->where('expense_date', '>=', $earliest)
            ->whereNotNull('wallet_id')
            ->selectRaw('wallet_id, expense_date, amount')
            ->get()
            ->each(function ($row) use (&$spent, $wallets) {
                $wallet = $wallets->firstWhere('id', $row->wallet_id);
                if ($wallet && $row->expense_date->toDateString() >= $wallet->balance_as_of->toDateString()) {
                    $spent[$wallet->id] = ($spent[$wallet->id] ?? 0.0) + (float) $row->amount;
                }
            });

        // Other income (side hustle…) that went into a wallet.
        $income = [];
        $user->incomes()->whereNotNull('wallet_id')->where('income_date', '>=', $earliest)->get(['wallet_id', 'income_date', 'amount'])
            ->each(function (Income $row) use (&$income, $wallets) {
                $wallet = $wallets->firstWhere('id', $row->wallet_id);
                if ($wallet && $row->income_date->toDateString() >= $wallet->balance_as_of->toDateString()) {
                    $income[$wallet->id] = ($income[$wallet->id] ?? 0.0) + (float) $row->amount;
                }
            });

        $saved = [];
        $user->savingsTransactions()
            ->where('transaction_date', '>=', $earliest)
            ->whereNotNull('wallet_id')
            ->get(['wallet_id', 'type', 'transaction_date', 'amount'])
            ->each(function (SavingsTransaction $row) use (&$saved, $wallets) {
                $wallet = $wallets->firstWhere('id', $row->wallet_id);
                if ($wallet && $row->transaction_date->toDateString() >= $wallet->balance_as_of->toDateString()) {
                    $saved[$wallet->id] = ($saved[$wallet->id] ?? 0.0) + $row->signedAmount();
                }
            });

        // Loans: the principal received (borrowed) or handed out (lent), then every payment.
        $loans = [];
        $user->loans()->whereNotNull('wallet_id')->where('start_date', '>=', $earliest)->get(['wallet_id', 'type', 'start_date', 'principal_amount'])
            ->each(function (Loan $loan) use (&$loans, $wallets) {
                $wallet = $wallets->firstWhere('id', $loan->wallet_id);
                if ($wallet && $loan->start_date->toDateString() >= $wallet->balance_as_of->toDateString()) {
                    $loans[$wallet->id] = ($loans[$wallet->id] ?? 0.0) + ($loan->isBorrowed() ? 1 : -1) * (float) $loan->principal_amount;
                }
            });
        $user->loanPayments()->with('loan:id,type')->whereNotNull('wallet_id')->where('via_payroll', false)->where('payment_date', '>=', $earliest)->get()
            ->each(function (LoanPayment $payment) use (&$loans, $wallets) {
                $wallet = $wallets->firstWhere('id', $payment->wallet_id);
                if ($wallet && $payment->loan && $payment->payment_date->toDateString() >= $wallet->balance_as_of->toDateString()) {
                    $loans[$wallet->id] = ($loans[$wallet->id] ?? 0.0) + ($payment->loan->isBorrowed() ? -1 : 1) * (float) $payment->amount;
                }
            });

        // Money saved for a goal sits in the wallet that keeps the goal (deposits land there, withdrawals leave it).
        $goalWallets = $user->savingsGoals()->whereNotNull('wallet_id')->pluck('wallet_id', 'id');
        $goalsIn = [];
        if ($goalWallets->isNotEmpty()) {
            $user->savingsTransactions()->where('transaction_date', '>=', $earliest)->whereIn('savings_goal_id', $goalWallets->keys())
                ->get(['savings_goal_id', 'type', 'transaction_date', 'amount'])
                ->each(function (SavingsTransaction $row) use (&$goalsIn, $wallets, $goalWallets) {
                    $wallet = $wallets->firstWhere('id', $goalWallets[$row->savings_goal_id]);
                    if ($wallet && $row->transaction_date->toDateString() >= $wallet->balance_as_of->toDateString()) {
                        $goalsIn[$wallet->id] = ($goalsIn[$wallet->id] ?? 0.0) + $row->signedAmount();
                    }
                });
        }
        $goalsHeld = $user->savingsGoals()->whereNotNull('wallet_id')->where('type', '!=', 'spending_limit')->get(['wallet_id', 'current_amount'])
            ->groupBy('wallet_id')->map(fn (Collection $group) => (float) $group->sum('current_amount'));

        // Transfers between the user's own wallets: the amount moves from one to the other.
        $transfersIn = [];
        $transfersOut = [];
        $user->walletTransfers()->where('transfer_date', '>=', $earliest)->get(['from_wallet_id', 'to_wallet_id', 'transfer_date', 'amount'])
            ->each(function (WalletTransfer $transfer) use (&$transfersIn, &$transfersOut, $wallets) {
                $date = $transfer->transfer_date->toDateString();
                $from = $wallets->firstWhere('id', $transfer->from_wallet_id);
                $to = $wallets->firstWhere('id', $transfer->to_wallet_id);
                if ($from && $date >= $from->balance_as_of->toDateString()) {
                    $transfersOut[$from->id] = ($transfersOut[$from->id] ?? 0.0) + (float) $transfer->amount;
                }
                if ($to && $date >= $to->balance_as_of->toDateString()) {
                    $transfersIn[$to->id] = ($transfersIn[$to->id] ?? 0.0) + (float) $transfer->amount;
                }
            });

        foreach ($wallets as $wallet) {
            $salary = $wallet->receives_salary ? $this->salaryReceived($user, $wallet) : 0.0;
            $wallet->salary_received = Money::round($salary);
            $wallet->spent = Money::round($spent[$wallet->id] ?? 0.0);
            $wallet->saved = Money::round($saved[$wallet->id] ?? 0.0);
            $wallet->loans = Money::round($loans[$wallet->id] ?? 0.0);
            $wallet->other_income = Money::round($income[$wallet->id] ?? 0.0);
            $wallet->transfers_in = Money::round($transfersIn[$wallet->id] ?? 0.0);
            $wallet->transfers_out = Money::round($transfersOut[$wallet->id] ?? 0.0);
            $wallet->goals_in = Money::round($goalsIn[$wallet->id] ?? 0.0);
            $wallet->goals_held = Money::round((float) ($goalsHeld[$wallet->id] ?? 0.0));
            $wallet->balance = Money::round((float) $wallet->opening_balance + $salary + $wallet->other_income - $wallet->spent - $wallet->saved + $wallet->goals_in + $wallet->loans + $wallet->transfers_in - $wallet->transfers_out);
            $wallet->available = Money::round($wallet->balance - $wallet->goals_held);
        }

        return $wallets;
    }

    /**
     * Take-home pay of every cut-off paid on or after the wallet's start date.
     * Cached until the day changes or something affecting past salaries is edited.
     */
    public function salaryReceived(User $user, Wallet $wallet): float
    {
        $today = CarbonImmutable::now($user->timezone())->toDateString();
        $asOf = $wallet->balance_as_of->toDateString();
        $key = sprintf('wallet-salary:%d:%d:%s:%s', $user->id, MoneyVersion::get($user->id), $asOf, $today);

        return (float) Cache::remember($key, now()->addDay(), function () use ($user, $asOf, $today) {
            $total = 0.0;
            foreach ($this->periods->periodsPaidBetween($user, $asOf, $today) as $period) {
                $summary = $this->periods->compute($user, $period);
                if ($summary['tracked']) {
                    $total += (float) $summary['take_home'];
                }
            }

            return Money::round($total) ?? 0.0;
        });
    }
}
