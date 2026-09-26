<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRecurringExpenseRequest;
use App\Http\Resources\ExpenseResource;
use App\Http\Resources\RecurringExpenseResource;
use App\Models\RecurringExpense;
use App\Services\RecurringExpenseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RecurringExpenseController extends Controller
{
    public function __construct(protected RecurringExpenseService $recurring) {}

    public function index(Request $request): JsonResponse
    {
        return $this->ok(RecurringExpenseResource::collection($this->recurring->list($request->user())));
    }

    public function store(StoreRecurringExpenseRequest $request): JsonResponse
    {
        $item = $this->recurring->create($request->user(), $request->validated());

        return $this->created(new RecurringExpenseResource($item), 'Recurring expense added.');
    }

    public function update(StoreRecurringExpenseRequest $request, RecurringExpense $recurringExpense): JsonResponse
    {
        $this->authorize('update', $recurringExpense);

        return $this->ok(new RecurringExpenseResource($this->recurring->update($recurringExpense, $request->validated())), 'Recurring expense updated.');
    }

    public function destroy(Request $request, RecurringExpense $recurringExpense): JsonResponse
    {
        $this->authorize('delete', $recurringExpense);
        $this->recurring->delete($recurringExpense);

        return $this->ok(null, 'Recurring expense removed.');
    }

    /** Record the bill right now (early or extra payment). */
    public function payNow(Request $request, RecurringExpense $recurringExpense): JsonResponse
    {
        $this->authorize('update', $recurringExpense);
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $expense = $this->recurring->payNow($recurringExpense, $data['date'] ?? null);

        return $this->created(new ExpenseResource($expense), 'Expense recorded.');
    }
}
