<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateSalarySettingRequest;
use App\Http\Resources\SalarySettingResource;
use App\Services\AttendanceService;
use App\Services\SalaryService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalarySettingController extends Controller
{
    public function __construct(protected SalaryService $salary, protected AttendanceService $attendance) {}

    public function show(Request $request): JsonResponse
    {
        return $this->ok([
            'settings' => new SalarySettingResource($this->salary->settings($request->user())),
            'rates' => $this->salary->rates($request->user()),
        ]);
    }

    public function update(UpdateSalarySettingRequest $request): JsonResponse
    {
        $user = $request->user();
        $settings = $this->salary->settings($user);
        $settings->fill($request->validated())->save();
        $user->setRelation('salarySetting', $settings->fresh());

        // Keep recent history consistent with the new rates.
        $today = CarbonImmutable::now($user->timezone());
        $this->attendance->recalculateRange($user, $today->subDays(90)->toDateString(), $today->addDay()->toDateString());

        return $this->ok([
            'settings' => new SalarySettingResource($settings->fresh()),
            'rates' => $this->salary->rates($user),
        ], 'Salary settings saved.');
    }
}
