<?php

namespace App\Services;

use App\Mail\GoalInviteMail;
use App\Models\SavingsGoal;
use App\Models\SavingsGoalMember;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Shared goals. The owner invites people by email: they get an email (Resend) and,
 * if they already use the app, an in-app notification. Once they accept, the goal
 * shows up in their Savings tab and they add money to it from their own accounts.
 */
class GoalSharingService
{
    public function __construct(protected AppNotificationService $notifications) {}

    /** Every goal the user can see: their own plus the shared ones they accepted. */
    public function goalsFor(User $user): Builder
    {
        return SavingsGoal::query()
            ->where(function (Builder $query) use ($user) {
                $query->where('user_id', $user->id)
                    ->orWhereHas('members', fn (Builder $m) => $m->where('user_id', $user->id)->where('status', SavingsGoalMember::STATUS_ACCEPTED));
            })
            ->with(['wallet', 'owner', 'members.user']);
    }

    /** Whether the user owns the goal or is an accepted member. */
    public function canView(User $user, SavingsGoal $goal): bool
    {
        if ((int) $goal->user_id === (int) $user->id) {
            return true;
        }

        return $goal->members()->where('user_id', $user->id)->where('status', SavingsGoalMember::STATUS_ACCEPTED)->exists();
    }

    /** Ids of every goal the user may put money into. */
    public function contributableGoalIds(User $user): array
    {
        return $this->goalsFor($user)->pluck('savings_goals.id')->map(fn ($id) => (int) $id)->all();
    }

    public function invite(SavingsGoal $goal, User $inviter, string $email): SavingsGoalMember
    {
        $email = Str::lower(trim($email));
        if ($email === Str::lower($inviter->email)) {
            throw ValidationException::withMessages(['email' => ['That is your own email address.']]);
        }
        $existing = $goal->members()->where('email', $email)->first();
        if ($existing && $existing->status === SavingsGoalMember::STATUS_ACCEPTED) {
            throw ValidationException::withMessages(['email' => ['That person is already part of this goal.']]);
        }

        if (! $goal->is_shared) {
            $goal->forceFill(['is_shared' => true])->save();
        }

        $invitee = User::where('email', $email)->first();
        $member = $existing ?? new SavingsGoalMember(['savings_goal_id' => $goal->id, 'email' => $email]);
        $member->fill([
            'user_id' => $invitee?->id,
            'role' => SavingsGoalMember::ROLE_MEMBER,
            'status' => SavingsGoalMember::STATUS_PENDING,
            'token' => Str::random(48),
            'invited_by' => $inviter->id,
            'accepted_at' => null,
        ])->save();

        if ($invitee) {
            $this->notifications->notify($invitee, 'goal_invite', 'Invitation to a shared goal', "{$this->displayName($inviter)} invited you to save together for \"{$goal->name}\". Open Savings to accept.", ['goal_id' => $goal->id, 'member_id' => $member->id]);
        }

        try {
            Mail::to($email)->send(new GoalInviteMail($goal, $inviter, $member));
        } catch (\Throwable $e) {
            Log::warning('Goal invitation email failed', ['email' => $email, 'goal' => $goal->id, 'error' => $e->getMessage()]);
        }

        return $member->load('user');
    }

    /** Invitations waiting for this user (matched by email), newest first. */
    public function pendingFor(User $user): Collection
    {
        return SavingsGoalMember::query()
            ->where('status', SavingsGoalMember::STATUS_PENDING)
            ->where(fn (Builder $q) => $q->where('user_id', $user->id)->orWhere('email', Str::lower($user->email)))
            ->whereHas('goal')
            ->with(['goal.owner', 'inviter'])
            ->orderByDesc('id')
            ->get();
    }

    public function accept(User $user, SavingsGoalMember $member): SavingsGoalMember
    {
        $this->assertInvitee($user, $member);
        $member->forceFill(['user_id' => $user->id, 'status' => SavingsGoalMember::STATUS_ACCEPTED, 'accepted_at' => now()])->save();

        $goal = $member->goal;
        if ($goal && $goal->owner) {
            $this->notifications->notify($goal->owner, 'goal_joined', 'Someone joined your goal', "{$this->displayName($user)} accepted your invitation to \"{$goal->name}\".", ['goal_id' => $goal->id]);
        }

        return $member->fresh(['goal', 'user']);
    }

    public function decline(User $user, SavingsGoalMember $member): SavingsGoalMember
    {
        $this->assertInvitee($user, $member);
        $member->forceFill(['user_id' => $user->id, 'status' => SavingsGoalMember::STATUS_DECLINED])->save();

        return $member->fresh(['goal', 'user']);
    }

    /** The owner removes a member, or a member leaves. */
    public function remove(User $actor, SavingsGoalMember $member): void
    {
        $goal = $member->goal;
        $isOwner = $goal && (int) $goal->user_id === (int) $actor->id;
        $isSelf = (int) $member->user_id === (int) $actor->id;
        abort_unless($isOwner || $isSelf, 403);

        $member->delete();
        if ($goal && $isSelf && ! $isOwner && $goal->owner) {
            $this->notifications->notify($goal->owner, 'goal_left', 'Someone left your goal', "{$this->displayName($actor)} left \"{$goal->name}\".", ['goal_id' => $goal->id]);
        }
    }

    /** Tell the other people in a shared goal about a deposit. */
    public function announceContribution(SavingsGoal $goal, User $contributor, float $amount): void
    {
        if (! $goal->is_shared) {
            return;
        }
        $others = $goal->participants()->reject(fn (User $u) => (int) $u->id === (int) $contributor->id);
        foreach ($others as $other) {
            $this->notifications->notify($other, 'goal_contribution', "New money in \"{$goal->name}\"", "{$this->displayName($contributor)} added ₱".number_format(Money::round($amount), 2).'. Total saved: ₱'.number_format((float) $goal->current_amount, 2).'.', ['goal_id' => $goal->id]);
        }
    }

    private function assertInvitee(User $user, SavingsGoalMember $member): void
    {
        $matches = (int) $member->user_id === (int) $user->id || Str::lower($member->email) === Str::lower($user->email);
        abort_unless($matches, 403, 'This invitation is for someone else.');
        if ($member->status !== SavingsGoalMember::STATUS_PENDING) {
            throw ValidationException::withMessages(['invite' => ['This invitation was already answered.']]);
        }
    }

    private function displayName(User $user): string
    {
        return $user->profile?->nickname ?: ($user->profile?->full_name ?: $user->name);
    }
}
