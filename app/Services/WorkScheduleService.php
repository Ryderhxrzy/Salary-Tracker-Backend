<?php

namespace App\Services;

use App\Models\LeaveRecord;
use App\Models\User;
use App\Models\WorkSchedule;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class WorkScheduleService
{
    /**
     * Create the default Monday-Saturday schedule for a user that has none yet.
     *
     * @return Collection<int, WorkSchedule>
     */
    public function ensureDefaults(User $user): Collection
    {
        if ($user->workSchedules()->count() === 7) {
            return $user->workSchedules()->get();
        }

        $defaults = config('salary_tracker.default_schedule');
        foreach ($defaults as $day => $values) {
            $user->workSchedules()->firstOrCreate(['day_of_week' => $day], $values);
        }

        return $user->workSchedules()->get();
    }

    /**
     * Replace the weekly schedule. $days is a list of 7 entries keyed by day_of_week.
     *
     * @param  array<int, array{is_working_day: bool, start_time?: ?string, end_time?: ?string, break_minutes?: int}>  $days
     */
    public function update(User $user, array $days): Collection
    {
        foreach ($days as $day) {
            $working = (bool) ($day['is_working_day'] ?? false);
            $user->workSchedules()->updateOrCreate(
                ['day_of_week' => (int) $day['day_of_week']],
                [
                    'is_working_day' => $working,
                    'start_time' => $working ? ($day['start_time'] ?? '08:00') : null,
                    'end_time' => $working ? ($day['end_time'] ?? '17:00') : null,
                    'break_minutes' => $working ? (int) ($day['break_minutes'] ?? 60) : 0,
                ]
            );
        }

        return $user->workSchedules()->get();
    }

    public function forDate(User $user, CarbonInterface $dateInUserTz): ?WorkSchedule
    {
        $this->ensureDefaults($user);

        return $user->workSchedules()->where('day_of_week', $dateInUserTz->dayOfWeek)->first();
    }

    public function leaveForDate(User $user, CarbonInterface $dateInUserTz): ?LeaveRecord
    {
        return $user->leaveRecords()->whereDate('leave_date', $dateInUserTz->toDateString())->first();
    }

    /**
     * Resolve the scheduled window for a calendar date (in the user's timezone).
     * Returns null on rest days. Overnight shifts end on the following day.
     *
     * @return array{schedule: WorkSchedule, start: CarbonImmutable, end: CarbonImmutable, break_minutes: int, expected_minutes: int}|null
     */
    public function windowForDate(User $user, CarbonInterface $dateInUserTz): ?array
    {
        $schedule = $this->forDate($user, $dateInUserTz);
        if (! $schedule || ! $schedule->is_working_day || ! $schedule->start_time || ! $schedule->end_time) {
            return null;
        }

        $tz = $user->timezone();
        $day = CarbonImmutable::parse($dateInUserTz->toDateString(), $tz);
        [$sh, $sm] = array_map('intval', explode(':', $schedule->start_time));
        [$eh, $em] = array_map('intval', explode(':', $schedule->end_time));

        $start = $day->setTime($sh, $sm);
        $end = $day->setTime($eh, $em);
        if ($end->lessThanOrEqualTo($start)) {
            $end = $end->addDay();
        }

        return [
            'schedule' => $schedule,
            'start' => $start,
            'end' => $end,
            'break_minutes' => $schedule->break_minutes,
            'expected_minutes' => $schedule->expectedWorkMinutes(),
        ];
    }

    /**
     * True when the user is expected to work on that date (schedule + no leave/rest override).
     */
    public function isWorkingDay(User $user, CarbonInterface $dateInUserTz): bool
    {
        if ($this->windowForDate($user, $dateInUserTz) === null) {
            return false;
        }

        return $this->leaveForDate($user, $dateInUserTz) === null;
    }

    public function workingDaysPerWeek(User $user): int
    {
        $this->ensureDefaults($user);
        $count = $user->workSchedules()->where('is_working_day', true)->count();

        return max(1, $count);
    }

    /**
     * Average scheduled work minutes for a working day across the week.
     */
    public function averageExpectedMinutes(User $user): int
    {
        $days = $this->ensureDefaults($user)->where('is_working_day', true);
        if ($days->isEmpty()) {
            return 480;
        }

        return (int) round($days->avg(fn (WorkSchedule $d) => $d->expectedWorkMinutes()));
    }
}
