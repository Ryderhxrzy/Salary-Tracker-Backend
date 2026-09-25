<?php

namespace App\Http\Resources;

use App\Models\AttendanceRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AttendanceRecord */
class AttendanceRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $tz = $this->user?->timezone() ?? config('salary_tracker.timezone');

        return [
            'id' => $this->id,
            'work_date' => $this->work_date?->toDateString(),
            'scheduled_start' => $this->scheduled_start?->toIso8601String(),
            'scheduled_end' => $this->scheduled_end?->toIso8601String(),
            'time_in' => $this->time_in?->toIso8601String(),
            'time_out' => $this->time_out?->toIso8601String(),
            'time_in_label' => $this->time_in?->setTimezone($tz)->format('g:i A'),
            'time_out_label' => $this->time_out?->setTimezone($tz)->format('g:i A'),
            'break_minutes' => $this->break_minutes,
            'worked_minutes' => $this->worked_minutes,
            'regular_minutes' => $this->regular_minutes,
            'overtime_minutes' => $this->overtime_minutes,
            'late_minutes' => $this->late_minutes,
            'status' => $this->status,
            'source' => $this->source,
            'is_on_duty' => $this->isOnDuty(),
            'is_completed' => $this->isCompleted(),
            'regular_amount' => $this->regular_amount === null ? null : (float) $this->regular_amount,
            'overtime_amount' => $this->overtime_amount === null ? null : (float) $this->overtime_amount,
            'salary_amount' => $this->salary_amount === null ? null : (float) $this->salary_amount,
            'notes' => $this->notes,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
