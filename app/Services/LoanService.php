<?php

namespace App\Services;

use App\Http\Resources\LoanPaymentResource;
use App\Http\Resources\LoanResource;
use App\Models\Loan;
use App\Models\LoanPayment;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Loans (money owed / money lent) and their payments.
 *
 *   borrowed, paid from a wallet   -> money out of the wallet, subtracted from "left to spend"
 *   borrowed, taken from payslip   -> lowers the take-home pay of the cut-off
 *   lent, repayment received       -> money back into the wallet, added to "left to spend"
 */
class LoanService
{
    public function __construct(protected WalletService $wallets) {}

    /** @return HasMany<Loan, User> */
    protected function query(User $user): HasMany
    {
        return $user->loans()->withSum('payments', 'amount')->withCount('payments')->with('wallet');
    }

    /**
     * @param  string  $status  active | paid | closed | all
     * @return Collection<int, Loan>
     */
    public function list(User $user, string $status = 'all'): Collection
    {
        $loans = $this->query($user)->orderBy('is_closed')->orderByRaw('next_due_date IS NULL, next_due_date')->orderByDesc('id')->get();

        return match ($status) {
            'active' => $loans->filter(fn (Loan $l) => ! $l->is_closed && ! $l->isPaid())->values(),
            'paid' => $loans->filter(fn (Loan $l) => ! $l->is_closed && $l->isPaid())->values(),
            'closed' => $loans->where('is_closed', true)->values(),
            default => $loans,
        };
    }

    public function find(User $user, int $id): ?Loan
    {
        return $this->query($user)->with('payments.wallet')->find($id);
    }

    public function overview(User $user, string $from, string $to): array
    {
        $loans = $this->list($user);
        $recent = $user->loanPayments()->with(['loan', 'wallet'])->orderByDesc('payment_date')->orderByDesc('id')->limit(30)->get();

        return $this->totals($user, $loans) + [
            'range' => ['from' => $from, 'to' => $to],
            'period' => $this->sumsBetween($user, $from, $to),
            'loans' => LoanResource::collection($loans),
            'payments' => LoanPaymentResource::collection($recent),
        ];
    }

    /**
     * What is still owed / owed to the user and the nearest due date.
     *
     * @param  Collection<int, Loan>|null  $loans
     */
    public function totals(User $user, ?Collection $loans = null): array
    {
        $loans ??= $this->list($user);
        $active = $loans->filter(fn (Loan $l) => ! $l->is_closed && ! $l->isPaid());
        $today = CarbonImmutable::now($user->timezone())->toDateString();
        /** @var Loan|null $next */
        $next = $active->filter(fn (Loan $l) => $l->next_due_date !== null)->sortBy(fn (Loan $l) => $l->next_due_date->toDateString())->first();

        return [
            'owed' => Money::sum($active->filter(fn (Loan $l) => $l->isBorrowed())->map(fn (Loan $l) => $l->remainingAmount())),
            'receivable' => Money::sum($active->filter(fn (Loan $l) => ! $l->isBorrowed())->map(fn (Loan $l) => $l->remainingAmount())),
            'active_count' => $active->count(),
            'overdue_count' => $active->filter(fn (Loan $l) => $l->next_due_date !== null && $l->next_due_date->toDateString() < $today)->count(),
            'next_due' => $next ? [
                'loan_id' => $next->id,
                'name' => $next->name,
                'type' => $next->type,
                'amount' => Money::round(min($next->remainingAmount(), (float) ($next->installment_amount ?? $next->remainingAmount()))),
                'due_date' => $next->next_due_date->toDateString(),
                'is_overdue' => $next->next_due_date->toDateString() < $today,
            ] : null,
        ];
    }

    /**
     * Payments dated in a range, split by how they affect the money flow.
     *
     * @return array{paid: float, payroll: float, received: float}
     */
    public function sumsBetween(User $user, string $from, string $to): array
    {
        $rows = $user->loanPayments()
            ->join('loans', 'loans.id', '=', 'loan_payments.loan_id')
            ->whereNull('loans.deleted_at')
            ->whereBetween('loan_payments.payment_date', [$from, $to])
            ->selectRaw('loans.type as loan_type, loan_payments.via_payroll as payroll, SUM(loan_payments.amount) as total')
            ->groupBy('loans.type', 'loan_payments.via_payroll')
            ->get();

        $paid = 0.0;
        $payroll = 0.0;
        $received = 0.0;
        foreach ($rows as $row) {
            $total = (float) $row->total;
            if ($row->loan_type === Loan::TYPE_LENT) {
                $received += $total;
            } elseif ((bool) $row->payroll) {
                $payroll += $total;
            } else {
                $paid += $total;
            }
        }

        return ['paid' => Money::round($paid) ?? 0.0, 'payroll' => Money::round($payroll) ?? 0.0, 'received' => Money::round($received) ?? 0.0];
    }

    public function create(User $user, array $data): Loan
    {
        $data['type'] = $data['type'] ?? Loan::TYPE_BORROWED;
        $data['total_amount'] = $data['total_amount'] ?? $data['principal_amount'];
        $data['frequency'] = $data['frequency'] ?? 'per_cutoff';
        $data['next_due_date'] = $data['next_due_date'] ?? $this->firstDueDate($data['start_date'], $data['frequency']);
        /** @var Loan $loan */
        $loan = $user->loans()->create($data);

        return $this->find($user, $loan->id);
    }

    public function update(User $user, Loan $loan, array $data): Loan
    {
        $loan->fill($data)->save();

        return $this->find($user, $loan->id);
    }

    public function delete(Loan $loan): void
    {
        DB::transaction(function () use ($loan) {
            $loan->payments()->delete();
            $loan->delete();
        });
    }

    public function addPayment(User $user, Loan $loan, array $data): LoanPayment
    {
        return DB::transaction(function () use ($user, $loan, $data) {
            $viaPayroll = array_key_exists('via_payroll', $data) ? (bool) $data['via_payroll'] : ($loan->isBorrowed() && $loan->via_payroll);
            $data['via_payroll'] = $viaPayroll;
            if (! $viaPayroll && empty($data['wallet_id'])) {
                $data['wallet_id'] = $loan->wallet_id ?? $this->wallets->forMethod($user, null)?->id;
            }
            if ($viaPayroll) {
                $data['wallet_id'] = null;
            }
            /** @var LoanPayment $payment */
            $payment = $user->loanPayments()->create($data + ['loan_id' => $loan->id]);
            $this->advanceDueDate($loan->fresh(), $payment->payment_date->toDateString());

            return $payment->load(['loan', 'wallet']);
        });
    }

    public function updatePayment(LoanPayment $payment, array $data): LoanPayment
    {
        if (! empty($data['via_payroll'])) {
            $data['wallet_id'] = null;
        }
        $payment->fill($data)->save();

        return $payment->fresh(['loan', 'wallet']);
    }

    public function deletePayment(LoanPayment $payment): void
    {
        $payment->delete();
    }

    /** The first due date after the start date for the chosen frequency. */
    protected function firstDueDate(string $startDate, string $frequency): ?string
    {
        if ($frequency === 'none') {
            return null;
        }

        return $this->nextDate(CarbonImmutable::parse($startDate), $frequency)->toDateString();
    }

    /**
     * A recorded payment settles the current due date: the next one moves one
     * interval forward (and further, if the payment was late by several periods).
     */
    protected function advanceDueDate(Loan $loan, string $paymentDate): void
    {
        if ($loan->frequency === 'none' || $loan->next_due_date === null) {
            return;
        }
        if ($loan->isPaid()) {
            $loan->next_due_date = null;
            $loan->save();

            return;
        }
        $next = $this->nextDate(CarbonImmutable::parse($loan->next_due_date->toDateString()), $loan->frequency);
        $guard = 0;
        while ($next->toDateString() <= $paymentDate && $guard++ < 120) {
            $next = $this->nextDate($next, $loan->frequency);
        }
        $loan->next_due_date = $next->toDateString();
        $loan->save();
    }

    protected function nextDate(CarbonImmutable $from, string $frequency): CarbonImmutable
    {
        return match ($frequency) {
            'weekly' => $from->addWeek(),
            'monthly' => $from->addMonthNoOverflow(),
            default => $from->addDays(15), // per_cutoff: two paydays a month
        };
    }
}
