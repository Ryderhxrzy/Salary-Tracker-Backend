<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RangeRequest;
use App\Http\Requests\StoreLeaveRecordRequest;
use App\Http\Resources\LeaveRecordResource;
use App\Models\LeaveRecord;
use App\Services\AttendanceService;
use App\Services\StatisticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LeaveRecordController extends Controller
{
    public function __construct(protected AttendanceService $attendance, protected StatisticsService $statistics) {}

    public function index(RangeRequest $request): JsonResponse
    {
        $user = $request->user();
        $range = $this->statistics->resolveRange($user, $request->range(), $request->input('from'), $request->input('to'));
        $records = $user->leaveRecords()->whereBetween('leave_date', [$range['from'], $range['to']])->orderByDesc('leave_date')->get();

        return $this->ok(['range' => $range, 'records' => LeaveRecordResource::collection($records)]);
    }

    public function store(StoreLeaveRecordRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();

        $leave = DB::transaction(function () use ($user, $data) {
            $leave = $user->leaveRecords()->updateOrCreate(['leave_date' => $data['leave_date']], $data);
            $this->syncAttendance($user, $leave);

            return $leave;
        });

        return $this->created(new LeaveRecordResource($leave), 'Day marked as '.str_replace('_', ' ', $leave->type).'.');
    }

    public function update(StoreLeaveRecordRequest $request, LeaveRecord $leave): JsonResponse
    {
        $this->authorize('update', $leave);
        $leave->fill($request->validated())->save();
        $this->syncAttendance($request->user(), $leave->fresh());

        return $this->ok(new LeaveRecordResource($leave->fresh()), 'Leave updated.');
    }

    public function destroy(Request $request, LeaveRecord $leave): JsonResponse
    {
        $this->authorize('delete', $leave);
        $user = $request->user();

        DB::transaction(function () use ($user, $leave) {
            $record = $user->attendanceRecords()->whereDate('work_date', $leave->leave_date->toDateString())->first();
            if ($record && $record->time_in === null) {
                $record->delete();
            }
            $leave->delete();
        });

        return $this->ok(null, 'Leave removed.');
    }

    /**
     * Mirror the leave onto the attendance record for that day (unless the user actually worked).
     */
    protected function syncAttendance($user, LeaveRecord $leave): void
    {
        $record = $user->attendanceRecords()->whereDate('work_date', $leave->leave_date->toDateString())->first();
        if ($record && $record->time_in !== null) {
            return;
        }

        $this->attendance->saveManual($user, [
            'work_date' => $leave->leave_date->toDateString(),
            'status' => $leave->attendanceStatus(),
            'notes' => $leave->notes,
        ], $record);
    }
}
