<?php

namespace App\Services;

use App\Models\Loan;
use App\Models\LoanPayment;
use App\Models\SavingsTransaction;
use App\Models\User;
use App\Models\Wallet;
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
 */
class WalletService
{
    public function __construct(protected SalaryPeriodService $periods) {}

    /**
     * The user's wallets, loaded once per request. Nothing is created automatically:
     * the user adds their own accounts (bank, e-wallet, cash).
     *
     * @return Collection<int, Wallet>
     */
    public function ensureDefaults(User $user): Collection
    {
        if ($user->relationLoaded('wallets')) {
            return $user->wallets;
        }
        $wallets = $user->wallets()->get();
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
        $byType = $wallets->groupBy('type')->map(fn (Collection $group) => $group->sortBy('sort_order')->first());
        $default = $wallets->firstWhere('is_default', true) ?? $wallets->first();
        $earliest = $wallets->min(fn (Wallet $w) => $w->balance_as_of->toDateString());

        // Expenses: those without a wallet fall back to the wallet of their payment method.
        $spent = [];
        $user->expenses()
            ->where('expense_date', '>=', $earliest)
            ->selectRaw('wallet_id, payment_method, expense_date, amount')
            ->get()
            ->each(function ($row) use (&$spent, $wallets, $byType, $default) {
                $wallet = ($row->wallet_id ? $wallets->firstWhere('id', $row->wallet_id) : null) ?? $byType->get($row->payment_method) ?? $default;
                if ($wallet && $row->expense_date->toDateString() >= $wallet->balance_as_of->toDateString()) {
                    $spent[$wallet->id] = ($spent[$wallet->id] ?? 0.0) + (float) $row->amount;
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

        foreach ($wallets as $wallet) {
            $salary = $wallet->receives_salary ? $this->salaryReceived($user, $wallet) : 0.0;
            $wallet->salary_received = Money::round($salary);
            $wallet->spent = Money::round($spent[$wallet->id] ?? 0.0);
            $wallet->saved = Money::round($saved[$wallet->id] ?? 0.0);
            $wallet->loans = Money::round($loans[$wallet->id] ?? 0.0);
            $wallet->balance = Money::round((float) $wallet->opening_balance + $salary - $wallet->spent - $wallet->saved + $wallet->loans);
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
