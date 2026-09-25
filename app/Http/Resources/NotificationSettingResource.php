<?php

namespace App\Http\Resources;

use App\Models\NotificationSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin NotificationSetting */
class NotificationSettingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'work_notification' => (bool) $this->work_notification,
            'work_notification_lead_minutes' => $this->work_notification_lead_minutes,
            'before_work_reminder' => (bool) $this->before_work_reminder,
            'before_work_minutes' => $this->before_work_minutes,
            'still_on_duty_reminder' => (bool) $this->still_on_duty_reminder,
            'still_on_duty_minutes' => $this->still_on_duty_minutes,
            'salary_period_reminder' => (bool) $this->salary_period_reminder,
            'expense_reminder' => (bool) $this->expense_reminder,
            'expense_reminder_time' => substr((string) $this->expense_reminder_time, 0, 5),
        ];
    }
}
