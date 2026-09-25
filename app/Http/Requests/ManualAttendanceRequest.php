<?php

namespace App\Http\Requests;

use App\Models\AttendanceRecord;
use Illuminate\Validation\Rule;

class ManualAttendanceRequest extends ApiFormRequest
{
    public const FIELDS = ['work_date', 'time_in', 'time_out', 'status', 'break_minutes', 'notes'];

    public function rules(): array
    {
        return static::rulesFor($this->route('attendance') !== null);
    }

    public static function rulesFor(bool $isUpdate = false): array
    {
        return [
            'work_date' => [$isUpdate ? 'nullable' : 'required', 'date_format:Y-m-d'],
            'time_in' => ['nullable', 'string', 'max:40'],
            'time_out' => ['nullable', 'string', 'max:40'],
            'status' => ['nullable', Rule::in(AttendanceRecord::STATUSES)],
            'break_minutes' => ['nullable', 'integer', 'min:0', 'max:480'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function attendanceData(): array
    {
        return $this->only(self::FIELDS);
    }
}
