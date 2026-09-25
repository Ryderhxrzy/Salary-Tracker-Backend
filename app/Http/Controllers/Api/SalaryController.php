<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RangeRequest;
use App\Http\Resources\SalaryPeriodResource;
use App\Http\Resources\SalarySettingResource;
use App\Models\SalaryPeriod;
use App\Services\SalaryPeriodService;
use App\Services\SalaryService;
use App\Services\StatisticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalaryController extends Controller
{
    public function __construct(
        protected SalaryService $salary,
        protected SalaryPeriodService $periods,
        protected StatisticsService $statistics,
    ) {}

    /**
     * GET /salary — settings, rates, current period with its computation and the
     * previous period while it is still waiting for its payday.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $period = $this->periods->currentPeriod($user);
        $previous = $this->periods->previousPeriod($user, $period);
        $previousSummary = $this->periods->compute($user, $previous);

        return $this->ok([
            'settings' => new SalarySettingResource($this->salary->settings($user)),
            'rates' => $this->salary->rates($user),
            'period' => new SalaryPeriodResource($period),
            'summary' => $this->periods->compute($user, $period),
            'payday' => $previousSummary['status'] === SalaryPeriodService::STATUS_COMPLETED
                ? ['period' => new SalaryPeriodResource($previous), 'summary' => $previousSummary]
                : null,
        ]);
    }

    /**
     * GET /salary/periods — current + previous periods, each with its computation.
     */
    public function periods(Request $request): JsonResponse
    {
        $user = $request->user();
        $count = min(36, max(1, (int) $request->input('count', 12)));
        $periods = collect($this->periods->recentPeriods($user, $count))->map(function (SalaryPeriod $period) use ($user) {
            $period->summary = $this->periods->compute($user, $period);

            return $period;
        });

        return $this->ok(SalaryPeriodResource::collection($periods));
    }

    /**
     * GET /salary/summary?range=... — totals for an arbitrary date range.
     */
    public function summary(RangeRequest $request): JsonResponse
    {
        $user = $request->user();
        $range = $this->statistics->resolveRange($user, $request->range(), $request->input('from'), $request->input('to'));

        return $this->ok([
            'range' => $range,
            'summary' => $this->periods->summary($user, $range['from'], $range['to']),
        ]);
    }
}
