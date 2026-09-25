<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['leave_date', 'type', 'is_paid', 'notes'])]
class LeaveRecord extends Model
{
    use SoftDeletes;

    public const TYPES = ['leave', 'sick_leave', 'vacation', 'absent', 'rest_day', 'holiday'];

    protected function casts(): array
    {
        return [
            'leave_date' => 'date:Y-m-d',
            'is_paid' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Attendance status that mirrors this leave type. */
    public function attendanceStatus(): string
    {
        return match ($this->type) {
            'absent' => AttendanceRecord::STATUS_ABSENT,
            'rest_day', 'holiday' => AttendanceRecord::STATUS_REST_DAY,
            default => AttendanceRecord::STATUS_LEAVE,
        };
    }
}
