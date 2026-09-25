<?php

namespace App\Services;

use App\Http\Resources\SalaryPeriodResource;
use App\Models\SalaryReceipt;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * "Receive salary": a finished cut-off waits on the dashboard until the user confirms
 * the pay arrived; only then is the take-home credited to the salary wallet. Several
 * cut-offs can wait at once (a late pay day keeps the older one showing).
 */
class SalaryReceiptService
{
    /** How many recent cut-offs are checked for a missing receipt. */
    protected const LOOKBACK = 6;

    public function __construct(protected SalaryPeriodService $periods, protected SalaryService $salary, protected WalletService $wallets) {}

    /**
     * Finished, tracked cut-offs without a receipt, oldest first.
     *
     * @return array<int, array{period: SalaryPeriodResource, summary: array, wallet: ?array, days_since_pay: int}>
     */
    public function pending(User $user): array
    {
        if (! $this->salary->settings($user)->isConfigured()) {
            return [];
        }
        $today = CarbonImmutable::now($user->timezone())->toDateString();
        $received = $user->salaryReceipts()->pluck('id', 'period_from')->keys()->map(fn ($d) => (string) $d)->all();
        $wallet = $this->wallets->ensureDefaults($user)->firstWhere('receives_salary', true);
        $walletRef = $wallet ? ['id' => $wallet->id, 'name' => $wallet->name, 'type' => $wallet->type] : null;

        $items = [];
        foreach ($this->periods->recentPeriods($user, self::LOOKBACK) as $period) {
            if ($period->end_date->toDateString() >= $today) {
                continue; // still running
            }
            if (in_array($period->start_date->toDateString(), $received, true)) {
                continue;
            }
            $summary = $this->periods->compute($user, $period);
            if (! $summary['tracked']) {
                continue; // no attendance at all: before the app was used
            }
            $items[] = [
                'period' => new SalaryPeriodResource($period),
                'summary' => $summary,
                'wallet' => $walletRef,
                'days_since_pay' => -(int) $summary['days_until_pay'],
            ];
        }

        return array_reverse($items);
    }

    /** Confirm a cut-off's pay arrived. Amount and wallet default to the computed take-home and the salary wallet. */
    public function receive(User $user, array $data): SalaryReceipt
    {
        $tz = $user->timezone();
        $today = CarbonImmutable::now($tz)->toDateString();
        $period = $this->periods->periodFor($user, CarbonImmutable::parse($data['period_from'], $tz));
        if ($period->start_date->toDateString() !== $data['period_from'] || $period->end_date->toDateString() !== $data['period_to']) {
            throw ValidationException::withMessages(['period_from' => ['That is not one of your cut-offs.']]);
        }
        if ($period->end_date->toDateString() >= $today) {
            throw ValidationException::withMessages(['period_to' => ['This cut-off has not ended yet.']]);
        }
        if ($user->salaryReceipts()->where('period_from', $data['period_from'])->exists()) {
            throw ValidationException::withMessages(['period_from' => ['This salary was already received.']]);
        }

        $summary = $this->periods->compute($user, $period);
        $wallet = ! empty($data['wallet_id'])
            ? $user->wallets()->find($data['wallet_id'])
            : $this->wallets->ensureDefaults($user)->firstWhere('receives_salary', true);

        $receipt = $user->salaryReceipts()->create([
            'salary_period_id' => $period->id,
            'wallet_id' => $wallet?->id,
            'period_from' => $period->start_date->toDateString(),
            'period_to' => $period->end_date->toDateString(),
            'pay_date' => $summary['pay_date'],
            'amount' => $data['amount'] ?? $summary['take_home'] ?? 0,
            'received_date' => $data['received_date'] ?? $today,
            'notes' => $data['notes'] ?? null,
        ]);
        $user->unsetRelation('wallets');

        return $receipt->load('wallet');
    }

    public function delete(SalaryReceipt $receipt): void
    {
        $receipt->delete();
    }

    /** Newest receipts first. */
    public function history(User $user, int $limit = 24)
    {
        return $user->salaryReceipts()->with('wallet')->orderByDesc('period_from')->limit(max(1, min(100, $limit)))->get();
    }
}
