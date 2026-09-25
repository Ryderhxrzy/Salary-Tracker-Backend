<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AttendanceActionRequest;
use App\Http\Requests\ManualAttendanceRequest;
use App\Http\Requests\RangeRequest;
use App\Http\Resources\AttendanceRecordResource;
use App\Models\AttendanceRecord;
use App\Services\AttendanceService;
use App\Services\NotificationService;
use App\Services\StatisticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    public function __construct(
        protected AttendanceService $attendance,
        protected NotificationService $notifications,
        protected StatisticsService $statistics,
    ) {}

    public function index(RangeRequest $request): JsonResponse
    {
        $user = $request->user();
        $range = $this->statistics->resolveRange($user, $request->range(), $request->input('from'), $request->input('to'));

        $records = $user->attendanceRecords()
            ->betweenDates($range['from'], $range['to'])
            ->orderByDesc('work_date')
            ->get();

        return $this->ok([
            'range' => $range,
            'records' => AttendanceRecordResource::collection($records),
        ]);
    }

    public function today(Request $request): JsonResponse
    {
        $state = $this->attendance->todayState($request->user());

        return $this->ok([
            'state' => $state['state'],
            'date' => $state['date'],
            'attendance' => $state['record'] ? new AttendanceRecordResource($state['record']) : null,
            'notification' => $this->notifications->plan($request->user(), $state),
        ]);
    }

    public function show(Request $request, AttendanceRecord $attendance): JsonResponse
    {
        $this->authorize('view', $attendance);

        return $this->ok(new AttendanceRecordResource($attendance));
    }

    public function timeIn(AttendanceActionRequest $request): JsonResponse
    {
        $result = $this->attendance->timeIn($request->user(), $request->options());

        return $this->respondWithAction($request, $result, 'Time in recorded successfully.');
    }

    public function timeOut(AttendanceActionRequest $request): JsonResponse
    {
        $result = $this->attendance->timeOut($request->user(), $request->options());

        return $this->respondWithAction($request, $result, 'Time out recorded successfully.');
    }

    public function manual(ManualAttendanceRequest $request): JsonResponse
    {
        $record = $this->attendance->saveManual($request->user(), $request->attendanceData());

        return $this->created(new AttendanceRecordResource($record), 'Attendance saved.');
    }

    public function update(ManualAttendanceRequest $request, AttendanceRecord $attendance): JsonResponse
    {
        $this->authorize('update', $attendance);
        $record = $this->attendance->saveManual($request->user(), $request->attendanceData(), $attendance);

        return $this->ok(new AttendanceRecordResource($record), 'Attendance updated.');
    }

    public function destroy(Request $request, AttendanceRecord $attendance): JsonResponse
    {
        $this->authorize('delete', $attendance);
        $this->attendance->delete($attendance);

        return $this->ok(null, 'Attendance deleted.');
    }

    /**
     * @param  array{record: AttendanceRecord, already_recorded: bool}  $result
     */
    protected function respondWithAction(Request $request, array $result, string $message): JsonResponse
    {
        $state = $this->attendance->todayState($request->user());

        return $this->ok([
            'attendance' => new AttendanceRecordResource($result['record']),
            'already_recorded' => $result['already_recorded'],
            'state' => $state['state'],
            'notification' => $this->notifications->plan($request->user(), $state),
        ], $result['already_recorded'] ? 'This action was already recorded.' : $message);
    }
}
