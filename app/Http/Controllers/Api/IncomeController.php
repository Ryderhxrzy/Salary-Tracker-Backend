<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RangeRequest;
use App\Http\Requests\StoreIncomeRequest;
use App\Http\Resources\IncomeResource;
use App\Models\Income;
use App\Services\StatisticsService;
use App\Services\WalletService;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Other income (side hustle, freelance…): money that is not the salary. */
class IncomeController extends Controller
{
    public function __construct(protected StatisticsService $statistics, protected WalletService $wallets) {}

    public function index(RangeRequest $request): JsonResponse
    {
        $user = $request->user();
        $range = $this->statistics->resolveRange($user, $request->range(), $request->input('from'), $request->input('to'));
        $items = $user->incomes()->with('wallet')->whereBetween('income_date', [$range['from'], $range['to']])->orderByDesc('income_date')->orderByDesc('id')->get();

        return $this->ok([
            'range' => $range,
            'total' => Money::sum($items->pluck('amount')),
            'incomes' => IncomeResource::collection($items),
        ]);
    }

    public function store(StoreIncomeRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();
        $data['wallet_id'] = $data['wallet_id'] ?? $this->wallets->forMethod($user, null)?->id;
        $income = $user->incomes()->create($data);

        return $this->created(new IncomeResource($income->load('wallet')), 'Income added.');
    }

    public function update(StoreIncomeRequest $request, Income $income): JsonResponse
    {
        $this->authorize('update', $income);
        $income->fill($request->validated())->save();

        return $this->ok(new IncomeResource($income->fresh('wallet')), 'Income updated.');
    }

    public function destroy(Request $request, Income $income): JsonResponse
    {
        $this->authorize('delete', $income);
        $income->delete();

        return $this->ok(null, 'Income deleted.');
    }
}
