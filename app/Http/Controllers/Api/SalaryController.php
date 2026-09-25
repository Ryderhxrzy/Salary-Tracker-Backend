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
     * GET /salary — settings, rates, current period and its summary.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $period = $this->periods->currentPeriod($user);

        return $this->ok([
            'settings' => new SalarySettingResource($this->salary->settings($user)),
            'rates' => $this->salary->rates($user),
            'period' => new SalaryPeriodResource($period),
            'summary' => $this->periods->summary($user, $period->start_date->toDateString(), $period->end_date->toDateString()),
        ]);
    }

    /**
     * GET /salary/periods — current + previous periods, each with a summary.
     */
    public function periods(Request $request): JsonResponse
    {
        $user = $request->user();
        $count = min(36, max(1, (int) $request->input('count', 12)));
        $periods = collect($this->periods->recentPeriods($user, $count))->map(function (SalaryPeriod $period) use ($user) {
            $period->summary = $this->periods->summary($user, $period->start_date->toDateString(), $period->end_date->toDateString());

            return $period;
        });

        return $this->ok(SalaryPeriodResource::collection($periods));
    }

    /**
     * GET /salary/summary?range=...
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
