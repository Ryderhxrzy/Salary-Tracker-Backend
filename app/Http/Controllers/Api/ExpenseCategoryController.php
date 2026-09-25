<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreExpenseCategoryRequest;
use App\Http\Resources\ExpenseCategoryResource;
use App\Models\ExpenseCategory;
use App\Services\ExpenseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExpenseCategoryController extends Controller
{
    public function __construct(protected ExpenseService $expenses) {}

    public function index(Request $request): JsonResponse
    {
        return $this->ok(ExpenseCategoryResource::collection($this->expenses->ensureDefaultCategories($request->user())));
    }

    public function store(StoreExpenseCategoryRequest $request): JsonResponse
    {
        $category = $request->user()->expenseCategories()->create($request->validated() + ['is_default' => false]);

        return $this->created(new ExpenseCategoryResource($category), 'Category added.');
    }

    public function update(StoreExpenseCategoryRequest $request, ExpenseCategory $category): JsonResponse
    {
        $this->authorize('update', $category);
        $category->fill($request->validated())->save();

        return $this->ok(new ExpenseCategoryResource($category->fresh()), 'Category updated.');
    }

    public function destroy(Request $request, ExpenseCategory $category): JsonResponse
    {
        $this->authorize('delete', $category);
        $category->delete();

        return $this->ok(null, 'Category deleted.');
    }
}
