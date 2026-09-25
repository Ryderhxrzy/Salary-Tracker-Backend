<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RangeRequest;
use App\Http\Requests\StoreSalaryAdjustmentRequest;
use App\Http\Resources\SalaryAdjustmentResource;
use App\Models\SalaryAdjustment;
use App\Services\StatisticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalaryAdjustmentController extends Controller
{
    public function __construct(protected StatisticsService $statistics) {}

    public function index(RangeRequest $request): JsonResponse
    {
        $user = $request->user();
        $range = $this->statistics->resolveRange($user, $request->range(), $request->input('from'), $request->input('to'));
        $items = $user->salaryAdjustments()
            ->whereBetween('adjustment_date', [$range['from'], $range['to']])
            ->orderByDesc('adjustment_date')->orderByDesc('id')
            ->get();

        return $this->ok(['range' => $range, 'adjustments' => SalaryAdjustmentResource::collection($items)]);
    }

    public function store(StoreSalaryAdjustmentRequest $request): JsonResponse
    {
        $item = $request->user()->salaryAdjustments()->create($request->validated());

        return $this->created(new SalaryAdjustmentResource($item), 'Adjustment added.');
    }

    public function update(StoreSalaryAdjustmentRequest $request, SalaryAdjustment $adjustment): JsonResponse
    {
        $this->authorize('update', $adjustment);
        $adjustment->fill($request->validated())->save();

        return $this->ok(new SalaryAdjustmentResource($adjustment->fresh()), 'Adjustment updated.');
    }

    public function destroy(Request $request, SalaryAdjustment $adjustment): JsonResponse
    {
        $this->authorize('delete', $adjustment);
        $adjustment->delete();

        return $this->ok(null, 'Adjustment deleted.');
    }
}
