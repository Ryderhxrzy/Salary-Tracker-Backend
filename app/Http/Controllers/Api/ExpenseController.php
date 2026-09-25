<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RangeRequest;
use App\Http\Requests\StoreExpenseRequest;
use App\Http\Resources\ExpenseResource;
use App\Models\Expense;
use App\Services\ExpenseService;
use App\Services\StatisticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExpenseController extends Controller
{
    public function __construct(protected ExpenseService $expenses, protected StatisticsService $statistics) {}

    public function index(RangeRequest $request): JsonResponse
    {
        $user = $request->user();
        $range = $this->statistics->resolveRange($user, $request->range(), $request->input('from'), $request->input('to'));

        $paginator = $this->expenses->list($user, [
            'from' => $range['from'],
            'to' => $range['to'],
            'category_id' => $request->input('category_id'),
            'search' => $request->input('search'),
            'per_page' => $request->input('per_page'),
        ]);

        return $this->ok([
            'range' => $range,
            'total' => $this->expenses->totalBetween($user, $range['from'], $range['to']),
            'expenses' => ExpenseResource::collection($paginator->items()),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function summary(RangeRequest $request): JsonResponse
    {
        $user = $request->user();
        $range = $this->statistics->resolveRange($user, $request->range(), $request->input('from'), $request->input('to'));

        return $this->ok([
            'range' => $range,
            'total' => $this->expenses->totalBetween($user, $range['from'], $range['to']),
            'by_category' => $this->expenses->byCategory($user, $range['from'], $range['to']),
            'by_day' => $this->expenses->byDay($user, $range['from'], $range['to']),
        ]);
    }

    public function store(StoreExpenseRequest $request): JsonResponse
    {
        $expense = $this->expenses->create($request->user(), $request->validated());

        return $this->created(new ExpenseResource($expense), 'Expense added.');
    }

    public function show(Request $request, Expense $expense): JsonResponse
    {
        $this->authorize('view', $expense);

        return $this->ok(new ExpenseResource($expense->load('category')));
    }

    public function update(StoreExpenseRequest $request, Expense $expense): JsonResponse
    {
        $this->authorize('update', $expense);
        $expense = $this->expenses->update($expense, $request->validated());

        return $this->ok(new ExpenseResource($expense), 'Expense updated.');
    }

    public function destroy(Request $request, Expense $expense): JsonResponse
    {
        $this->authorize('delete', $expense);
        $this->expenses->delete($expense);

        return $this->ok(null, 'Expense deleted.');
    }
}
