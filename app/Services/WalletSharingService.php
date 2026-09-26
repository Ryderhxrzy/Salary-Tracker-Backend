<?php

namespace App\Services;

use App\Mail\WalletInviteMail;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletMember;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Shared accounts. The owner invites people by email; once they accept, the
 * account appears in their wallet strip and they can spend, save and transfer
 * from it like their own. Every movement counts on the one shared balance.
 */
class WalletSharingService
{
    public function __construct(protected AppNotificationService $notifications) {}

    /** Every wallet the user can use: their own plus the shared ones they accepted. */
    public function walletsFor(User $user): Builder
    {
        return Wallet::query()
            ->where(function (Builder $query) use ($user) {
                $query->where('user_id', $user->id)
                    ->orWhereHas('members', fn (Builder $m) => $m->where('user_id', $user->id)->where('status', WalletMember::STATUS_ACCEPTED));
            })
            ->with(['owner.profile', 'members.user.profile'])
            ->orderBy('sort_order')->orderBy('id');
    }

    /** @return int[] */
    public function accessibleWalletIds(User $user): array
    {
        return $this->walletsFor($user)->pluck('wallets.id')->map(fn ($id) => (int) $id)->all();
    }

    public function canView(User $user, Wallet $wallet): bool
    {
        if ((int) $wallet->user_id === (int) $user->id) {
            return true;
        }

        return $wallet->members()->where('user_id', $user->id)->where('status', WalletMember::STATUS_ACCEPTED)->exists();
    }

    public function invite(Wallet $wallet, User $inviter, string $email): WalletMember
    {
        $email = Str::lower(trim($email));
        if ($email === Str::lower($inviter->email)) {
            throw ValidationException::withMessages(['email' => ['That is your own email address.']]);
        }
        $existing = $wallet->members()->where('email', $email)->first();
        if ($existing && $existing->status === WalletMember::STATUS_ACCEPTED) {
            throw ValidationException::withMessages(['email' => ['That person already uses this account.']]);
        }
        if (! $wallet->is_shared) {
            $wallet->forceFill(['is_shared' => true])->save();
        }

        $invitee = User::where('email', $email)->first();
        $member = $existing ?? new WalletMember(['wallet_id' => $wallet->id, 'email' => $email]);
        $member->fill([
            'user_id' => $invitee?->id,
            'role' => 'member',
            'status' => WalletMember::STATUS_PENDING,
            'token' => Str::random(48),
            'invited_by' => $inviter->id,
            'accepted_at' => null,
        ])->save();

        if ($invitee) {
            $this->notifications->notify($invitee, 'wallet_invite', 'Invitation to a shared account', "{$this->displayName($inviter)} invited you to use the account \"{$wallet->name}\" together. Open Savings to accept.", ['wallet_id' => $wallet->id, 'member_id' => $member->id]);
        }
        try {
            Mail::to($email)->send(new WalletInviteMail($wallet, $inviter, $member));
        } catch (\Throwable $e) {
            Log::warning('Wallet invitation email failed', ['email' => $email, 'wallet' => $wallet->id, 'error' => $e->getMessage()]);
        }

        return $member->load('user');
    }

    public function pendingFor(User $user): Collection
    {
        return WalletMember::query()
            ->where('status', WalletMember::STATUS_PENDING)
            ->where(fn (Builder $q) => $q->where('user_id', $user->id)->orWhere('email', Str::lower($user->email)))
            ->whereHas('wallet')
            ->with(['wallet.owner.profile', 'inviter.profile'])
            ->orderByDesc('id')
            ->get();
    }

    public function accept(User $user, WalletMember $member): WalletMember
    {
        $this->assertInvitee($user, $member);
        $member->forceFill(['user_id' => $user->id, 'status' => WalletMember::STATUS_ACCEPTED, 'accepted_at' => now()])->save();
        $user->unsetRelation('wallets');
        $wallet = $member->wallet;
        if ($wallet && $wallet->owner) {
            $this->notifications->notify($wallet->owner, 'wallet_joined', 'Someone joined your account', "{$this->displayName($user)} now uses \"{$wallet->name}\" with you.", ['wallet_id' => $wallet->id]);
        }

        return $member->fresh(['wallet', 'user']);
    }

    public function decline(User $user, WalletMember $member): WalletMember
    {
        $this->assertInvitee($user, $member);
        $member->forceFill(['user_id' => $user->id, 'status' => WalletMember::STATUS_DECLINED])->save();

        return $member->fresh(['wallet', 'user']);
    }

    public function remove(User $actor, WalletMember $member): void
    {
        $wallet = $member->wallet;
        $isOwner = $wallet && (int) $wallet->user_id === (int) $actor->id;
        $isSelf = (int) $member->user_id === (int) $actor->id;
        abort_unless($isOwner || $isSelf, 403);
        $member->delete();
        if ($wallet && $isSelf && ! $isOwner && $wallet->owner) {
            $this->notifications->notify($wallet->owner, 'wallet_left', 'Someone left your account', "{$this->displayName($actor)} stopped using \"{$wallet->name}\".", ['wallet_id' => $wallet->id]);
        }
    }

    /** Tell the other people using a shared account about money that moved. */
    public function announce(Wallet $wallet, User $actor, string $what): void
    {
        if (! $wallet->is_shared) {
            return;
        }
        foreach ($wallet->participants() as $other) {
            if ((int) $other->id === (int) $actor->id) {
                continue;
            }
            $this->notifications->notify($other, 'wallet_activity', "Activity in \"{$wallet->name}\"", "{$this->displayName($actor)} {$what}", ['wallet_id' => $wallet->id]);
        }
    }

    private function assertInvitee(User $user, WalletMember $member): void
    {
        $matches = (int) $member->user_id === (int) $user->id || Str::lower($member->email) === Str::lower($user->email);
        abort_unless($matches, 403, 'This invitation is for someone else.');
        if ($member->status !== WalletMember::STATUS_PENDING) {
            throw ValidationException::withMessages(['invite' => ['This invitation was already answered.']]);
        }
    }

    private function displayName(User $user): string
    {
        return $user->profile?->nickname ?: ($user->profile?->full_name ?: $user->name);
    }
}
