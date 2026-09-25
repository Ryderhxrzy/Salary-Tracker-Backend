<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RangeRequest;
use App\Http\Requests\StoreSavingsTransactionRequest;
use App\Http\Resources\SavingsTransactionResource;
use App\Models\SavingsTransaction;
use App\Services\SavingsService;
use App\Services\StatisticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SavingsController extends Controller
{
    public function __construct(protected SavingsService $savings, protected StatisticsService $statistics) {}

    /** GET /savings?range=... — total saved, goals, wallets and recent transactions. */
    public function index(RangeRequest $request): JsonResponse
    {
        $user = $request->user();
        $range = $this->statistics->resolveRange($user, $request->range(), $request->input('from'), $request->input('to'));

        return $this->ok($this->savings->overview($user, $range));
    }

    /** GET /savings/transactions?range=...&goal_id= */
    public function transactions(RangeRequest $request): JsonResponse
    {
        $user = $request->user();
        $range = $this->statistics->resolveRange($user, $request->range(), $request->input('from'), $request->input('to'));
        $query = $user->savingsTransactions()->with(['goal', 'wallet'])
            ->whereBetween('transaction_date', [$range['from'], $range['to']])
            ->orderByDesc('transaction_date')->orderByDesc('id');
        if ($request->filled('goal_id')) {
            $query->where('savings_goal_id', (int) $request->input('goal_id'));
        }

        return $this->ok(['range' => $range, 'transactions' => SavingsTransactionResource::collection($query->limit(200)->get())]);
    }

    public function store(StoreSavingsTransactionRequest $request): JsonResponse
    {
        $transaction = $this->savings->create($request->user(), $request->validated());

        return $this->created(new SavingsTransactionResource($transaction), $transaction->isDeposit() ? 'Added to savings.' : 'Withdrawn from savings.');
    }

    public function update(StoreSavingsTransactionRequest $request, SavingsTransaction $transaction): JsonResponse
    {
        $this->authorize('update', $transaction);
        $transaction = $this->savings->update($transaction, $request->validated());

        return $this->ok(new SavingsTransactionResource($transaction), 'Savings updated.');
    }

    public function destroy(Request $request, SavingsTransaction $transaction): JsonResponse
    {
        $this->authorize('delete', $transaction);
        $this->savings->delete($transaction);

        return $this->ok(null, 'Savings entry removed.');
    }
}
