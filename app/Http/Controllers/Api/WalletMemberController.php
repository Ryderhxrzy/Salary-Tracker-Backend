<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\WalletMemberResource;
use App\Http\Resources\WalletResource;
use App\Models\Wallet;
use App\Models\WalletMember;
use App\Services\WalletService;
use App\Services\WalletSharingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Invitations and members of shared accounts. */
class WalletMemberController extends Controller
{
    public function __construct(protected WalletSharingService $sharing, protected WalletService $wallets) {}

    public function invite(Request $request, Wallet $wallet): JsonResponse
    {
        $this->authorize('update', $wallet);
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:190']]);
        $member = $this->sharing->invite($wallet, $request->user(), $data['email']);

        return $this->created(new WalletMemberResource($member), "Invitation sent to {$member->email}.");
    }

    public function remove(Request $request, Wallet $wallet, WalletMember $member): JsonResponse
    {
        abort_unless((int) $member->wallet_id === (int) $wallet->id, 404);
        $this->sharing->remove($request->user(), $member);

        return $this->ok(null, 'Removed from the account.');
    }

    public function pending(Request $request): JsonResponse
    {
        return $this->ok(WalletMemberResource::collection($this->sharing->pendingFor($request->user())));
    }

    public function accept(Request $request, WalletMember $member): JsonResponse
    {
        $member = $this->sharing->accept($request->user(), $member);
        $wallet = $this->wallets->withBalances($request->user())->firstWhere('id', $member->wallet_id) ?? $member->wallet;

        return $this->ok(new WalletResource($wallet), "You now use \"{$member->wallet->name}\".");
    }

    public function decline(Request $request, WalletMember $member): JsonResponse
    {
        $this->sharing->decline($request->user(), $member);

        return $this->ok(null, 'Invitation declined.');
    }
}
