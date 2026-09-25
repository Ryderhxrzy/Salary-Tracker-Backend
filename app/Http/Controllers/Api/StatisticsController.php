<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RangeRequest;
use App\Services\StatisticsService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StatisticsController extends Controller
{
    public function __construct(protected StatisticsService $statistics) {}

    public function index(RangeRequest $request): JsonResponse
    {
        $user = $request->user();
        $range = $this->statistics->resolveRange($user, $request->range(), $request->input('from'), $request->input('to'));

        return $this->ok(['label' => $range['label']] + $this->statistics->build($user, $range['from'], $range['to']));
    }

    public function calendar(Request $request): JsonResponse
    {
        $user = $request->user();
        $today = CarbonImmutable::now($user->timezone());
        $year = (int) $request->input('year', $today->year);
        $month = (int) $request->input('month', $today->month);

        if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
            return $this->fail('Invalid year or month.', 422);
        }

        return $this->ok($this->statistics->calendar($user, $year, $month));
    }
}
