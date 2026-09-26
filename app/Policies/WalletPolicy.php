<?php

namespace App\Policies;

use App\Models\User;
use App\Services\WalletSharingService;
use Illuminate\Database\Eloquent\Model;

/** An account is edited and deleted by its owner only; accepted members of a shared account may view and use it. */
class WalletPolicy extends OwnedResourcePolicy
{
    public function view(User $user, Model $model): bool
    {
        return $this->owns($user, $model) || app(WalletSharingService::class)->canView($user, $model);
    }
}
