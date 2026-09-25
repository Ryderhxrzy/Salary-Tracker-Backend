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
            'fees' => Money::sum($items->pluck('fee')),
            'transfers' => WalletTransferResource::collection($items),
        ]);
    }

    public function store(StoreWalletTransferRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['fee'] = $data['fee'] ?? 0;
        $transfer = $request->user()->walletTransfers()->create($data);

        return $this->created(new WalletTransferResource($transfer->load(['fromWallet', 'toWallet'])), 'Transfer recorded.');
    }

    public function update(StoreWalletTransferRequest $request, WalletTransfer $transfer): JsonResponse
    {
        $this->authorize('update', $transfer);
        $data = $request->validated();
        if (array_key_exists('fee', $data) && $data['fee'] === null) {
            $data['fee'] = 0;
        }
        $transfer->fill($data)->save();

        return $this->ok(new WalletTransferResource($transfer->fresh(['fromWallet', 'toWallet'])), 'Transfer updated.');
    }

    public function destroy(Request $request, WalletTransfer $transfer): JsonResponse
    {
        $this->authorize('delete', $transfer);
        $transfer->delete();

        return $this->ok(null, 'Transfer deleted.');
    }
}
