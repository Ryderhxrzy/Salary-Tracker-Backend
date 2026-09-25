<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'work_date', 'scheduled_start', 'scheduled_end', 'time_in', 'time_out', 'break_minutes',
    'worked_minutes', 'regular_minutes', 'overtime_minutes', 'late_minutes', 'status', 'source',
    'regular_amount', 'overtime_amount', 'salary_amount', 'time_in_key', 'time_out_key', 'notes',
])]
class AttendanceRecord extends Model
{
    use SoftDeletes;

    public const STATUS_PRESENT = 'present';

    public const STATUS_LATE = 'late';

    public const STATUS_ABSENT = 'absent';

    public const STATUS_REST_DAY = 'rest_day';

    public const STATUS_LEAVE = 'leave';

    public const STATUS_INCOMPLETE = 'incomplete';

    public const STATUSES = ['present', 'late', 'absent', 'rest_day', 'leave', 'incomplete'];

    public const SOURCES = ['app', 'notification', 'manual', 'sync'];

    protected function casts(): array
    {
        return [
            'work_date' => 'date:Y-m-d',
            'scheduled_start' => 'datetime',
            'scheduled_end' => 'datetime',
            'time_in' => 'datetime',
            'time_out' => 'datetime',
            'break_minutes' => 'integer',
            'worked_minutes' => 'integer',
            'regular_minutes' => 'integer',
            'overtime_minutes' => 'integer',
            'late_minutes' => 'integer',
            'regular_amount' => 'decimal:2',
            'overtime_amount' => 'decimal:2',
            'salary_amount' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isOnDuty(): bool
    {
        return $this->time_in !== null && $this->time_out === null && $this->status !== self::STATUS_INCOMPLETE;
    }

    public function isCompleted(): bool
    {
        return $this->time_in !== null && $this->time_out !== null;
    }

    /** Records the user is still clocked into (forgotten/incomplete ones are excluded). */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotNull('time_in')->whereNull('time_out')->where('status', '!=', self::STATUS_INCOMPLETE);
    }

    public function scopeBetweenDates(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('work_date', [$from, $to]);
    }
}
