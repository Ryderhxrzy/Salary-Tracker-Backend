<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use App\Support\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class ExpenseService
{
    public function __construct(protected WalletService $wallets) {}

    public function ensureDefaultCategories(User $user): Collection
    {
        if ($user->expenseCategories()->exists()) {
            return $user->expenseCategories()->get();
        }

        foreach (ExpenseCategory::DEFAULTS as $index => $category) {
            $user->expenseCategories()->firstOrCreate(
                ['name' => $category['name']],
                ['icon' => $category['icon'], 'color' => $category['color'], 'is_default' => true, 'sort_order' => $index]
            );
        }

        return $user->expenseCategories()->get();
    }

    /**
     * @param  array{from?: ?string, to?: ?string, category_id?: ?int, search?: ?string, per_page?: ?int}  $filters
     */
    public function list(User $user, array $filters = []): LengthAwarePaginator
    {
        $query = $user->expenses()->with(['category', 'wallet'])->orderByDesc('expense_date')->orderByDesc('id');

        if (! empty($filters['from'])) {
            $query->where('expense_date', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->where('expense_date', '<=', $filters['to']);
        }
        if (! empty($filters['category_id'])) {
            $query->where('expense_category_id', $filters['category_id']);
        }
        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $query->where(fn ($q) => $q->where('description', 'like', $term)->orWhere('notes', 'like', $term));
        }

        return $query->paginate(min(100, (int) ($filters['per_page'] ?? 30)));
    }

    public function create(User $user, array $data): Expense
    {
        $expense = $user->expenses()->create($this->resolveWallet($user, $data));

        return $expense->load(['category', 'wallet']);
    }

    public function update(Expense $expense, array $data): Expense
    {
        $expense->fill($this->resolveWallet($expense->user, $data, $expense))->save();

        return $expense->fresh(['category', 'wallet']);
    }

    /**
     * Keep `wallet_id` and `payment_method` in agreement: a chosen wallet defines the
     * method (GCash wallet => gcash); a method alone picks the matching wallet.
     */
    protected function resolveWallet(User $user, array $data, ?Expense $existing = null): array
    {
        if (! empty($data['wallet_id'])) {
            $wallet = $user->wallets()->find($data['wallet_id']);
            if ($wallet && ! array_key_exists('payment_method', $data)) {
                $data['payment_method'] = $wallet->type;
            }
        } elseif (array_key_exists('wallet_id', $data)) {
            // Explicit null: not paid from any account, only tracked as an expense.
            $data['wallet_id'] = null;
            $data['payment_method'] = $data['payment_method'] ?? $existing?->payment_method ?? 'other';
        } elseif (array_key_exists('payment_method', $data) || $existing === null) {
            $method = $data['payment_method'] ?? $existing?->payment_method ?? 'cash';
            $data['wallet_id'] = $this->wallets->forMethod($user, $method)?->id;
            $data['payment_method'] = $method;
        }

        return $data;
    }

    public function delete(Expense $expense): void
    {
        $expense->delete();
    }

    public function totalBetween(User $user, string $from, string $to): float
    {
        return Money::round($user->expenses()->whereBetween('expense_date', [$from, $to])->sum('amount')) ?? 0.0;
    }

    /**
     * Totals per category for a range, sorted by amount.
     */
    public function byCategory(User $user, string $from, string $to): array
    {
        $rows = $user->expenses()
            ->with('category')
            ->whereBetween('expense_date', [$from, $to])
            ->get()
            ->groupBy(fn (Expense $e) => $e->expense_category_id ?? 0)
            ->map(function (Collection $group, $id) {
                $category = $group->first()->category;

                return [
                    'category_id' => $id ?: null,
                    'name' => $category?->name ?? 'Uncategorized',
                    'icon' => $category?->icon,
                    'color' => $category?->color ?? '#6B7280',
                    'total' => Money::sum($group->pluck('amount')),
                    'count' => $group->count(),
                ];
            })
            ->sortByDesc('total')
            ->values();

        return $rows->all();
    }

    /**
     * Totals per day for a range: ['2026-09-01' => 120.0, ...].
     */
    public function byDay(User $user, string $from, string $to): array
    {
        return $user->expenses()
            ->whereBetween('expense_date', [$from, $to])
            ->get()
            ->groupBy(fn (Expense $e) => $e->expense_date->toDateString())
            ->map(fn (Collection $group) => Money::sum($group->pluck('amount')))
            ->all();
    }
}
