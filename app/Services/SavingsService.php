<?php

namespace App\Services;

use App\Http\Resources\SavingsGoalResource;
use App\Http\Resources\SavingsTransactionResource;
use App\Http\Resources\WalletResource;
use App\Models\SavingsGoal;
use App\Models\SavingsTransaction;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Savings: money set aside from the salary. A deposit lowers the wallet it came
 * from and raises the goal it is for; a withdrawal does the opposite.
 *
 *   total savings = goal balances (savings / emergency fund goals) + loose savings (deposits without a goal)
 */
class SavingsService
{
    public function __construct(protected WalletService $wallets) {}

    public function overview(User $user, string $from, string $to): array
    {
        $goals = $user->savingsGoals()->orderBy('is_completed')->orderByDesc('id')->get();
        $goalBalance = Money::sum($goals->where('type', '!=', 'spending_limit')->pluck('current_amount'));
        $loose = $this->netBetween($user, null, null, withoutGoal: true);

        $deposits = (float) $user->savingsTransactions()->where('type', SavingsTransaction::TYPE_DEPOSIT)->whereBetween('transaction_date', [$from, $to])->sum('amount');
        $withdrawals = (float) $user->savingsTransactions()->where('type', SavingsTransaction::TYPE_WITHDRAWAL)->whereBetween('transaction_date', [$from, $to])->sum('amount');

        $recent = $user->savingsTransactions()->with(['goal', 'wallet'])->orderByDesc('transaction_date')->orderByDesc('id')->limit(30)->get();

        return [
            'total_saved' => Money::round($goalBalance + $loose),
            'goal_balance' => $goalBalance,
            'loose_savings' => Money::round($loose),
            'range' => ['from' => $from, 'to' => $to],
            'deposits' => Money::round($deposits),
            'withdrawals' => Money::round($withdrawals),
            'net' => Money::round($deposits - $withdrawals),
            'goals' => SavingsGoalResource::collection($goals),
            'transactions' => SavingsTransactionResource::collection($recent),
            'wallets' => WalletResource::collection($this->wallets->withBalances($user)),
        ];
    }

    /** Deposits − withdrawals in a range (all time when no bounds). */
    public function netBetween(User $user, ?string $from, ?string $to, bool $withoutGoal = false): float
    {
        $query = $user->savingsTransactions();
        if ($from && $to) {
            $query->whereBetween('transaction_date', [$from, $to]);
        }
        if ($withoutGoal) {
            $query->whereNull('savings_goal_id');
        }
        $deposits = (float) (clone $query)->where('type', SavingsTransaction::TYPE_DEPOSIT)->sum('amount');
        $withdrawals = (float) (clone $query)->where('type', SavingsTransaction::TYPE_WITHDRAWAL)->sum('amount');

        return Money::round($deposits - $withdrawals) ?? 0.0;
    }

    public function create(User $user, array $data): SavingsTransaction
    {
        return DB::transaction(function () use ($user, $data) {
            $data['type'] = $data['type'] ?? SavingsTransaction::TYPE_DEPOSIT;
            $data['wallet_id'] = $data['wallet_id'] ?? $this->wallets->forMethod($user, null)?->id;
            /** @var SavingsTransaction $transaction */
            $transaction = $user->savingsTransactions()->create($data);
            $this->applyToGoal($transaction->savings_goal_id, $transaction->signedAmount());

            return $transaction->load(['goal', 'wallet']);
        });
    }

    public function update(SavingsTransaction $transaction, array $data): SavingsTransaction
    {
        return DB::transaction(function () use ($transaction, $data) {
            // Undo the old effect on its goal, then apply the new one.
            $this->applyToGoal($transaction->savings_goal_id, -$transaction->signedAmount());
            $transaction->fill($data)->save();
            $this->applyToGoal($transaction->savings_goal_id, $transaction->signedAmount());

            return $transaction->fresh(['goal', 'wallet']);
        });
    }

    public function delete(SavingsTransaction $transaction): void
    {
        DB::transaction(function () use ($transaction) {
            $this->applyToGoal($transaction->savings_goal_id, -$transaction->signedAmount());
            $transaction->delete();
        });
    }

    /** Move a goal's saved amount by `$signed` (never below zero) and update its completion. */
    protected function applyToGoal(?int $goalId, float $signed): void
    {
        if (! $goalId || $signed === 0.0) {
            return;
        }
        /** @var SavingsGoal|null $goal */
        $goal = SavingsGoal::query()->lockForUpdate()->find($goalId);
        if (! $goal) {
            return;
        }
        $goal->current_amount = max(0.0, (float) $goal->current_amount + $signed);
        if ($goal->type !== 'spending_limit') {
            $goal->is_completed = (float) $goal->current_amount >= (float) $goal->target_amount;
        }
        $goal->save();
    }
}
