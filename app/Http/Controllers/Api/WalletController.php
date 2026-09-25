<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWalletRequest;
use App\Http\Resources\WalletResource;
use App\Models\Wallet;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function __construct(protected WalletService $wallets) {}

    /** GET /wallets — every wallet with its computed balance. */
    public function index(Request $request): JsonResponse
    {
        return $this->ok(WalletResource::collection($this->wallets->withBalances($request->user())));
    }

    public function store(StoreWalletRequest $request): JsonResponse
    {
        $wallet = $this->wallets->create($request->user(), $request->validated());

        return $this->created(new WalletResource($wallet), 'Wallet added.');
    }

    public function update(StoreWalletRequest $request, Wallet $wallet): JsonResponse
    {
        $this->authorize('update', $wallet);
        $wallet = $this->wallets->update($request->user(), $wallet, $request->validated());

        return $this->ok(new WalletResource($wallet), 'Wallet updated.');
    }

    public function destroy(Request $request, Wallet $wallet): JsonResponse
    {
        $this->authorize('delete', $wallet);
        if ($request->user()->wallets()->count() <= 1) {
            return $this->fail('You need at least one wallet.', 422, [], 'last_wallet');
        }
        $this->wallets->delete($request->user(), $wallet);

        return $this->ok(null, 'Wallet deleted.');
    }
}
