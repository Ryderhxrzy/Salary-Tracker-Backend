<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RangeRequest;
use App\Http\Requests\StoreSalaryAdjustmentRequest;
use App\Http\Resources\SalaryAdjustmentResource;
use App\Models\SalaryAdjustment;
use App\Services\StatisticsService;
use App\Support\Money;
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
            ->forPeriod($range['from'], $range['to'])
            ->orderByDesc('recurring')->orderByDesc('adjustment_date')->orderByDesc('id')
            ->get();

        return $this->ok([
            'range' => $range,
            'income' => Money::sum($items->filter(fn (SalaryAdjustment $a) => $a->isIncome())->pluck('amount')),
            'deductions' => Money::sum($items->filter(fn (SalaryAdjustment $a) => ! $a->isIncome())->pluck('amount')),
            'adjustments' => SalaryAdjustmentResource::collection($items),
        ]);
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
