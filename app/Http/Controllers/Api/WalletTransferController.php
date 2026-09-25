<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RangeRequest;
use App\Http\Requests\StoreWalletTransferRequest;
use App\Http\Resources\WalletTransferResource;
use App\Models\WalletTransfer;
use App\Services\StatisticsService;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Money moved between two of the user's wallets. Never touches the salary totals. */
class WalletTransferController extends Controller
{
    public function __construct(protected StatisticsService $statistics) {}

    public function index(RangeRequest $request): JsonResponse
    {
        $user = $request->user();
        $range = $this->statistics->resolveRange($user, $request->range(), $request->input('from'), $request->input('to'));
        $query = $user->walletTransfers()->with(['fromWallet', 'toWallet'])->whereBetween('transfer_date', [$range['from'], $range['to']]);
        if ($request->filled('wallet')) {
            $walletId = (int) $request->input('wallet');
            $query->where(fn ($q) => $q->where('from_wallet_id', $walletId)->orWhere('to_wallet_id', $walletId));
        }
        $items = $query->orderByDesc('transfer_date')->orderByDesc('id')->get();

        return $this->ok([
            'range' => $range,
            'total' => Money::sum($items->pluck('amount')),
            'transfers' => WalletTransferResource::collection($items),
        ]);
    }

    public function store(StoreWalletTransferRequest $request): JsonResponse
    {
        $transfer = $request->user()->walletTransfers()->create($request->validated());

        return $this->created(new WalletTransferResource($transfer->load(['fromWallet', 'toWallet'])), 'Transfer recorded.');
    }

    public function update(StoreWalletTransferRequest $request, WalletTransfer $transfer): JsonResponse
    {
        $this->authorize('update', $transfer);
        $transfer->fill($request->validated())->save();

        return $this->ok(new WalletTransferResource($transfer->fresh(['fromWallet', 'toWallet'])), 'Transfer updated.');
    }

    public function destroy(Request $request, WalletTransfer $transfer): JsonResponse
    {
        $this->authorize('delete', $transfer);
        $transfer->delete();

        return $this->ok(null, 'Transfer deleted.');
    }
}
