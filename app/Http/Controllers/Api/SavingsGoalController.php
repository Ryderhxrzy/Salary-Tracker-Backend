<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSavingsGoalRequest;
use App\Http\Resources\SavingsGoalResource;
use App\Models\SavingsGoal;
use App\Services\GoalSharingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SavingsGoalController extends Controller
{
    public function __construct(protected GoalSharingService $sharing) {}

    /** Own goals plus the shared goals this user accepted. */
    public function index(Request $request): JsonResponse
    {
        $goals = $this->sharing->goalsFor($request->user())->orderBy('is_completed')->orderByDesc('savings_goals.id')->get();

        return $this->ok(SavingsGoalResource::collection($goals));
    }

    public function store(StoreSavingsGoalRequest $request): JsonResponse
    {
        $data = $request->validated();
        $emails = $data['invite_emails'] ?? [];
        unset($data['invite_emails']);
        if ($emails) {
            $data['is_shared'] = true;
        }
        $goal = $request->user()->savingsGoals()->create($data);
        foreach ($emails as $email) {
            $this->sharing->invite($goal, $request->user(), $email);
        }

        return $this->created(new SavingsGoalResource($goal->load(['wallet', 'owner', 'members.user'])), 'Goal created.');
    }

    public function update(StoreSavingsGoalRequest $request, SavingsGoal $goal): JsonResponse
    {
        $this->authorize('update', $goal);
        $data = $request->validated();
        $emails = $data['invite_emails'] ?? [];
        unset($data['invite_emails']);
        if ($emails) {
            $data['is_shared'] = true;
        }
        $goal->fill($data);
        if ($goal->type !== 'spending_limit' && (float) $goal->current_amount >= (float) $goal->target_amount) {
            $goal->is_completed = true;
        }
        $goal->save();
        foreach ($emails as $email) {
            $this->sharing->invite($goal, $request->user(), $email);
        }

        return $this->ok(new SavingsGoalResource($goal->fresh(['wallet', 'owner', 'members.user'])), 'Goal updated.');
    }

    public function destroy(Request $request, SavingsGoal $goal): JsonResponse
    {
        $this->authorize('delete', $goal);
        $goal->delete();

        return $this->ok(null, 'Goal deleted.');
    }
}
