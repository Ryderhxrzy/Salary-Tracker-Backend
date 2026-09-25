<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSavingsGoalRequest;
use App\Http\Resources\SavingsGoalResource;
use App\Models\SavingsGoal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SavingsGoalController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $goals = $request->user()->savingsGoals()->orderBy('is_completed')->orderByDesc('id')->get();

        return $this->ok(SavingsGoalResource::collection($goals));
    }

    public function store(StoreSavingsGoalRequest $request): JsonResponse
    {
        $goal = $request->user()->savingsGoals()->create($request->validated());

        return $this->created(new SavingsGoalResource($goal), 'Goal created.');
    }

    public function update(StoreSavingsGoalRequest $request, SavingsGoal $goal): JsonResponse
    {
        $this->authorize('update', $goal);
        $goal->fill($request->validated());
        if ($goal->type !== 'spending_limit' && (float) $goal->current_amount >= (float) $goal->target_amount) {
            $goal->is_completed = true;
        }
        $goal->save();

        return $this->ok(new SavingsGoalResource($goal->fresh()), 'Goal updated.');
    }

    public function destroy(Request $request, SavingsGoal $goal): JsonResponse
    {
        $this->authorize('delete', $goal);
        $goal->delete();

        return $this->ok(null, 'Goal deleted.');
    }
}
