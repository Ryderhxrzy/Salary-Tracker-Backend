<?php

namespace App\Http\Requests;

class UpdateWorkScheduleRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return static::rulesFor();
    }

    public static function rulesFor(): array
    {
        return [
            'days' => ['required', 'array', 'min:1', 'max:7'],
            'days.*.day_of_week' => ['required', 'integer', 'min:0', 'max:6', 'distinct'],
            'days.*.is_working_day' => ['required', 'boolean'],
            'days.*.start_time' => ['nullable', 'required_if:days.*.is_working_day,true', 'date_format:H:i'],
            'days.*.end_time' => ['nullable', 'required_if:days.*.is_working_day,true', 'date_format:H:i'],
            'days.*.break_minutes' => ['nullable', 'integer', 'min:0', 'max:480'],
        ];
    }
}
