<?php

namespace App\Http\Resources;

use App\Models\WorkSchedule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin WorkSchedule */
class WorkScheduleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'day_of_week' => $this->day_of_week,
            'day_name' => $this->dayName(),
            'is_working_day' => (bool) $this->is_working_day,
            'start_time' => $this->startHm(),
            'end_time' => $this->endHm(),
            'break_minutes' => $this->break_minutes,
            'expected_minutes' => $this->expectedWorkMinutes(),
        ];
    }
}
