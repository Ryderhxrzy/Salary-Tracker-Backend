<?php

namespace App\Policies;

use App\Models\User;
use App\Services\GoalSharingService;
use Illuminate\Database\Eloquent\Model;

/** A goal is edited and deleted by its owner only; accepted members of a shared goal may view it. */
class SavingsGoalPolicy extends OwnedResourcePolicy
{
    public function view(User $user, Model $model): bool
    {
        return $this->owns($user, $model) || app(GoalSharingService::class)->canView($user, $model);
    }
}
