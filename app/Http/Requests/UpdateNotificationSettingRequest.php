<?php

namespace App\Http\Requests;

class UpdateNotificationSettingRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return static::rulesFor();
    }

    public static function rulesFor(): array
    {
        return [
            'work_notification' => ['nullable', 'boolean'],
            'work_notification_lead_minutes' => ['nullable', 'integer', 'min:0', 'max:720'],
            'before_work_reminder' => ['nullable', 'boolean'],
            'before_work_minutes' => ['nullable', 'integer', 'min:1', 'max:240'],
            'still_on_duty_reminder' => ['nullable', 'boolean'],
            'still_on_duty_minutes' => ['nullable', 'integer', 'min:1', 'max:600'],
            'salary_period_reminder' => ['nullable', 'boolean'],
            'expense_reminder' => ['nullable', 'boolean'],
            'expense_reminder_time' => ['nullable', 'date_format:H:i'],
        ];
    }
}
