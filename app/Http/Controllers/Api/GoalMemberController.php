<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SavingsGoalMemberResource;
use App\Http\Resources\SavingsGoalResource;
use App\Models\SavingsGoal;
use App\Models\SavingsGoalMember;
use App\Services\GoalSharingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Invitations and members of shared goals. */
class GoalMemberController extends Controller
{
    public function __construct(protected GoalSharingService $sharing) {}

    /** The owner invites someone by email. */
    public function invite(Request $request, SavingsGoal $goal): JsonResponse
    {
        $this->authorize('update', $goal);
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:190']]);
        $member = $this->sharing->invite($goal, $request->user(), $data['email']);

        return $this->created(new SavingsGoalMemberResource($member), "Invitation sent to {$member->email}.");
    }

    /** The owner removes a member (or cancels an invitation); a member leaves. */
    public function remove(Request $request, SavingsGoal $goal, SavingsGoalMember $member): JsonResponse
    {
        abort_unless((int) $member->savings_goal_id === (int) $goal->id, 404);
        $this->sharing->remove($request->user(), $member);

        return $this->ok(null, 'Removed from the goal.');
    }

    /** Invitations waiting for the signed-in user. */
    public function pending(Request $request): JsonResponse
    {
        return $this->ok(SavingsGoalMemberResource::collection($this->sharing->pendingFor($request->user())));
    }

    public function accept(Request $request, SavingsGoalMember $member): JsonResponse
    {
        $member = $this->sharing->accept($request->user(), $member);

        return $this->ok(new SavingsGoalResource($member->goal->load(['wallet', 'owner', 'members.user'])), "You joined \"{$member->goal->name}\".");
    }

    public function decline(Request $request, SavingsGoalMember $member): JsonResponse
    {
        $this->sharing->decline($request->user(), $member);

        return $this->ok(null, 'Invitation declined.');
    }
}
