<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'work_notification', 'work_notification_lead_minutes',
    'before_work_reminder', 'before_work_minutes',
    'still_on_duty_reminder', 'still_on_duty_minutes',
    'salary_period_reminder', 'expense_reminder', 'expense_reminder_time',
])]
class NotificationSetting extends Model
{
    protected function casts(): array
    {
        return [
            'work_notification' => 'boolean',
            'work_notification_lead_minutes' => 'integer',
            'before_work_reminder' => 'boolean',
            'before_work_minutes' => 'integer',
            'still_on_duty_reminder' => 'boolean',
            'still_on_duty_minutes' => 'integer',
            'salary_period_reminder' => 'boolean',
            'expense_reminder' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
