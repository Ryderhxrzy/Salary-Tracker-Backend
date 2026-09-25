<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateWorkScheduleRequest;
use App\Http\Resources\WorkScheduleResource;
use App\Services\WorkScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkScheduleController extends Controller
{
    public function __construct(protected WorkScheduleService $schedules) {}

    public function show(Request $request): JsonResponse
    {
        return $this->ok(WorkScheduleResource::collection($this->schedules->ensureDefaults($request->user())));
    }

    public function update(UpdateWorkScheduleRequest $request): JsonResponse
    {
        $days = $this->schedules->update($request->user(), $request->validated('days'));

        return $this->ok(WorkScheduleResource::collection($days), 'Work schedule saved.');
    }
}
