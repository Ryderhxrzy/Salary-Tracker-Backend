<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['day_of_week', 'is_working_day', 'start_time', 'end_time', 'break_minutes'])]
class WorkSchedule extends Model
{
    public const DAY_NAMES = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'is_working_day' => 'boolean',
            'break_minutes' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function dayName(): string
    {
        return self::DAY_NAMES[$this->day_of_week] ?? 'Unknown';
    }

    /** "08:00:00" -> "08:00" */
    public function startHm(): ?string
    {
        return $this->start_time ? substr($this->start_time, 0, 5) : null;
    }

    public function endHm(): ?string
    {
        return $this->end_time ? substr($this->end_time, 0, 5) : null;
    }

    /**
     * Scheduled duration in minutes (handles overnight shifts) before breaks.
     */
    public function scheduledMinutes(): int
    {
        if (! $this->is_working_day || ! $this->start_time || ! $this->end_time) {
            return 0;
        }
        [$sh, $sm] = array_map('intval', explode(':', $this->start_time));
        [$eh, $em] = array_map('intval', explode(':', $this->end_time));
        $start = $sh * 60 + $sm;
        $end = $eh * 60 + $em;
        if ($end <= $start) {
            $end += 24 * 60; // overnight
        }

        return $end - $start;
    }

    public function expectedWorkMinutes(): int
    {
        return max(0, $this->scheduledMinutes() - $this->break_minutes);
    }
}
