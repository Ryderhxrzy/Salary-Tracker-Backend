<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\AttendanceRecord;
use App\Models\LeaveRecord;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class AttendanceService
{
    public const STATE_REST_DAY = 'REST_DAY';

    public const STATE_LEAVE = 'LEAVE';

    public const STATE_SCHEDULED = 'SCHEDULED';

    public const STATE_ON_DUTY = 'ON_DUTY';

    public const STATE_COMPLETED = 'COMPLETED';

    public function __construct(
        protected WorkScheduleService $schedules,
        protected SalaryService $salary,
    ) {}

    // ---------------------------------------------------------------------
    // Time in / Time out
    // ---------------------------------------------------------------------

    /**
     * @param  array{source?: string, idempotency_key?: ?string, occurred_at?: ?string, notes?: ?string}  $options
     * @return array{record: AttendanceRecord, already_recorded: bool}
     */
    public function timeIn(User $user, array $options = []): array
    {
        $key = $options['idempotency_key'] ?? null;
        if ($key && ($existing = $user->attendanceRecords()->where('time_in_key', $key)->first())) {
            return ['record' => $existing, 'already_recorded' => true];
        }

        return DB::transaction(function () use ($user, $options, $key) {
            $now = CarbonImmutable::now('UTC');
            $at = $this->resolveOccurredAt($now, $options['occurred_at'] ?? null, $options['source'] ?? 'app');
            $tz = $user->timezone();

            $this->closeStaleOpenRecords($user, $now);

            $open = $user->attendanceRecords()->open()->lockForUpdate()->first();
            if ($open) {
                throw ApiException::conflict('You are currently on duty. Please time out first.', 'ALREADY_ON_DUTY');
            }

            $localAt = $at->setTimezone($tz);
            $workDate = $localAt->toDateString();

            $record = $user->attendanceRecords()->lockForUpdate()->whereDate('work_date', $workDate)->first();
            if ($record && $record->time_in !== null) {
                throw ApiException::conflict('You have already timed in today.', 'DUPLICATE_TIME_IN');
            }

            $record ??= new AttendanceRecord(['work_date' => $workDate]);
            $record->user_id = $user->id;

            $window = $this->schedules->windowForDate($user, $localAt);
            $lateMinutes = 0;
            if ($window) {
                $grace = (int) ($user->profile?->late_grace_minutes ?? 0);
                $threshold = $window['start']->addMinutes($grace);
                $lateMinutes = $at->greaterThan($threshold) ? (int) $threshold->diffInMinutes($at) : 0;
                $record->scheduled_start = $window['start']->utc();
                $record->scheduled_end = $window['end']->utc();
                $record->break_minutes = $window['break_minutes'];
            } else {
                $record->scheduled_start = null;
                $record->scheduled_end = null;
                $record->break_minutes = 0;
            }

            $record->time_in = $at;
            $record->time_out = null;
            $record->late_minutes = $lateMinutes;
            $record->status = $lateMinutes > 0 ? AttendanceRecord::STATUS_LATE : AttendanceRecord::STATUS_PRESENT;
            $record->source = $options['source'] ?? 'app';
            $record->time_in_key = $key;
            if (! empty($options['notes'])) {
                $record->notes = $options['notes'];
            }
            $record->worked_minutes = 0;
            $record->regular_minutes = 0;
            $record->overtime_minutes = 0;
            $record->regular_amount = null;
            $record->overtime_amount = null;
            $record->salary_amount = null;
            $record->save();

            return ['record' => $record->fresh(), 'already_recorded' => false];
        });
    }

    /**
     * @param  array{source?: string, idempotency_key?: ?string, occurred_at?: ?string, notes?: ?string}  $options
     * @return array{record: AttendanceRecord, already_recorded: bool}
     */
    public function timeOut(User $user, array $options = []): array
    {
        $key = $options['idempotency_key'] ?? null;
        if ($key && ($existing = $user->attendanceRecords()->where('time_out_key', $key)->first())) {
            return ['record' => $existing, 'already_recorded' => true];
        }

        return DB::transaction(function () use ($user, $options, $key) {
            $now = CarbonImmutable::now('UTC');
            $at = $this->resolveOccurredAt($now, $options['occurred_at'] ?? null, $options['source'] ?? 'app');

            $record = $user->attendanceRecords()->open()->lockForUpdate()->orderByDesc('time_in')->first();
            if (! $record) {
                $today = $now->setTimezone($user->timezone())->toDateString();
                $completed = $user->attendanceRecords()->whereDate('work_date', $today)->whereNotNull('time_out')->exists();
                if ($completed) {
                    throw ApiException::conflict('You have already timed out today.', 'DUPLICATE_TIME_OUT');
                }
                throw ApiException::conflict('No active time in found. Please time in first.', 'NO_ACTIVE_TIME_IN');
            }

            if ($at->lessThanOrEqualTo($record->time_in)) {
                throw ApiException::unprocessable('Time out must be after time in.', [], 'INVALID_TIME_OUT');
            }

            $record->time_out = $at;
            $record->time_out_key = $key;
            if (! empty($options['notes'])) {
                $record->notes = trim(($record->notes ? $record->notes."\n" : '').$options['notes']);
            }
            $this->recalculate($user, $record);
            $record->save();

            return ['record' => $record->fresh(), 'already_recorded' => false];
        });
    }

    // ---------------------------------------------------------------------
    // Manual entries
    // ---------------------------------------------------------------------

    /**
     * Create or replace an attendance record by hand (forgotten time in/out, corrections).
     *
     * @param  array{work_date: string, time_in?: ?string, time_out?: ?string, status?: ?string, notes?: ?string, break_minutes?: ?int}  $data
     */
    public function saveManual(User $user, array $data, ?AttendanceRecord $record = null): AttendanceRecord
    {
        return DB::transaction(function () use ($user, $data, $record) {
            $tz = $user->timezone();
            $workDate = $data['work_date'] ?? $record?->work_date?->toDateString();
            $day = CarbonImmutable::parse($workDate, $tz);

            $record ??= $user->attendanceRecords()->whereDate('work_date', $day->toDateString())->first()
                ?? new AttendanceRecord(['work_date' => $day->toDateString()]);
            $record->user_id = $user->id;
            $record->work_date = $day->toDateString();

            $window = $this->schedules->windowForDate($user, $day);
            $record->scheduled_start = $window ? $window['start']->utc() : null;
            $record->scheduled_end = $window ? $window['end']->utc() : null;
            $record->break_minutes = array_key_exists('break_minutes', $data) && $data['break_minutes'] !== null
                ? (int) $data['break_minutes']
                : ($window['break_minutes'] ?? 0);

            if (array_key_exists('time_in', $data)) {
                $record->time_in = $this->parseLocalTime($day, $data['time_in'], $tz);
            }
            if (array_key_exists('time_out', $data)) {
                $timeOut = $this->parseLocalTime($day, $data['time_out'], $tz);
                if ($timeOut && $record->time_in && $timeOut->lessThanOrEqualTo($record->time_in)) {
                    $timeOut = $timeOut->addDay(); // overnight shift
                }
                $record->time_out = $timeOut;
            }
            if (array_key_exists('notes', $data)) {
                $record->notes = $data['notes'];
            }
            $record->source = 'manual';

            $explicitStatus = $data['status'] ?? null;
            if ($record->time_in && $record->time_out) {
                if ($record->time_out->lessThanOrEqualTo($record->time_in)) {
                    throw ApiException::unprocessable('Time out must be after time in.', ['time_out' => ['Time out must be after time in.']]);
                }
                $this->recalculate($user, $record);
                if ($explicitStatus && in_array($explicitStatus, [AttendanceRecord::STATUS_PRESENT, AttendanceRecord::STATUS_LATE], true)) {
                    $record->status = $explicitStatus;
                }
            } elseif ($record->time_in && ! $record->time_out) {
                $this->clearComputed($record);
                $record->late_minutes = $this->lateMinutesFor($user, $record, $window);
                $record->status = $explicitStatus ?: AttendanceRecord::STATUS_INCOMPLETE;
            } else {
                $this->clearComputed($record);
                $record->late_minutes = 0;
                $record->status = $explicitStatus ?: ($window ? AttendanceRecord::STATUS_ABSENT : AttendanceRecord::STATUS_REST_DAY);
            }

            $record->save();

            return $record->fresh();
        });
    }

    public function delete(AttendanceRecord $record): void
    {
        $record->delete();
    }

    // ---------------------------------------------------------------------
    // Calculations
    // ---------------------------------------------------------------------

    /**
     * Recompute worked/regular/overtime minutes, lateness, status and pay for a
     * record that has both a time in and a time out.
     */
    public function recalculate(User $user, AttendanceRecord $record): AttendanceRecord
    {
        if (! $record->time_in || ! $record->time_out) {
            return $record;
        }

        $tz = $user->timezone();
        $day = CarbonImmutable::parse($record->work_date->toDateString(), $tz);
        $window = $this->schedules->windowForDate($user, $day);
        $settings = $this->salary->settings($user);

        $expected = $window ? $window['expected_minutes'] : (int) round((float) $settings->expected_hours_per_day * 60);
        $total = (int) $record->time_in->diffInMinutes($record->time_out);
        $worked = max(0, $total - $this->breakOverlapMinutes($record, $window));

        if ($settings->overtime_enabled) {
            $regular = min($worked, $expected);
            $overtime = max(0, $worked - $expected);
        } else {
            $regular = $worked;
            $overtime = 0;
        }

        $record->worked_minutes = $worked;
        $record->regular_minutes = $regular;
        $record->overtime_minutes = $overtime;
        $record->late_minutes = $this->lateMinutesFor($user, $record, $window);
        $record->status = $record->late_minutes > 0 ? AttendanceRecord::STATUS_LATE : AttendanceRecord::STATUS_PRESENT;

        $amounts = $this->salary->amountsFor($user, $record, $expected, $settings);
        $record->regular_amount = $amounts['regular_amount'];
        $record->overtime_amount = $amounts['overtime_amount'];
        $record->salary_amount = $amounts['salary_amount'];

        return $record;
    }

    /**
     * Re-run calculations for completed records in a date range (e.g. after salary settings change).
     */
    public function recalculateRange(User $user, string $from, string $to): int
    {
        $count = 0;
        $user->attendanceRecords()->betweenDates($from, $to)
            ->whereNotNull('time_in')->whereNotNull('time_out')
            ->each(function (AttendanceRecord $record) use ($user, &$count) {
                $this->recalculate($user, $record)->save();
                $count++;
            });

        return $count;
    }

    /**
     * Provisional metrics for an on-duty record as if the user timed out right now.
     */
    public function provisional(User $user, AttendanceRecord $record, ?CarbonInterface $asOf = null): AttendanceRecord
    {
        $clone = $record->replicate();
        $clone->work_date = $record->work_date;
        $clone->time_out = CarbonImmutable::instance($asOf ?? CarbonImmutable::now('UTC'));
        if ($clone->time_out->lessThanOrEqualTo($clone->time_in)) {
            $clone->time_out = $clone->time_in->copy()->addMinute();
        }

        return $this->recalculate($user, $clone);
    }

    // ---------------------------------------------------------------------
    // Today's state (shared by dashboard + notification plan)
    // ---------------------------------------------------------------------

    /**
     * @return array{state: string, date: string, record: ?AttendanceRecord, window: ?array, leave: ?LeaveRecord}
     */
    public function todayState(User $user, ?CarbonInterface $now = null): array
    {
        $now = CarbonImmutable::instance($now ?? CarbonImmutable::now('UTC'))->utc();
        $tz = $user->timezone();
        $today = $now->setTimezone($tz);

        $this->closeStaleOpenRecords($user, $now);

        $open = $user->attendanceRecords()->open()->orderByDesc('time_in')->first();
        $record = $open ?? $user->attendanceRecords()->whereDate('work_date', $today->toDateString())->first();
        $recordDay = $record ? CarbonImmutable::parse($record->work_date->toDateString(), $tz) : $today;

        $window = $this->schedules->windowForDate($user, $recordDay);
        $leave = $this->schedules->leaveForDate($user, $recordDay);

        $state = match (true) {
            $record?->isOnDuty() => self::STATE_ON_DUTY,
            $record?->isCompleted() => self::STATE_COMPLETED,
            $leave !== null => self::STATE_LEAVE,
            $window === null => self::STATE_REST_DAY,
            $record !== null && in_array($record->status, [AttendanceRecord::STATUS_ABSENT, AttendanceRecord::STATUS_LEAVE, AttendanceRecord::STATUS_REST_DAY], true) => self::STATE_LEAVE,
            default => self::STATE_SCHEDULED,
        };

        return [
            'state' => $state,
            'date' => $recordDay->toDateString(),
            'record' => $record,
            'window' => $window,
            'leave' => $leave,
        ];
    }

    /**
     * Mark open records from previous days as incomplete (forgotten time out).
     */
    public function closeStaleOpenRecords(User $user, CarbonImmutable $now): void
    {
        $hours = (int) config('salary_tracker.stale_open_hours', 12);
        $user->attendanceRecords()->open()
            ->get()
            ->each(function (AttendanceRecord $record) use ($now, $hours) {
                $deadline = $record->scheduled_end
                    ? CarbonImmutable::instance($record->scheduled_end)->addHours($hours)
                    : CarbonImmutable::instance($record->time_in)->addHours($hours + 4);
                if ($now->greaterThan($deadline)) {
                    $record->status = AttendanceRecord::STATUS_INCOMPLETE;
                    $record->save();
                }
            });
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    protected function lateMinutesFor(User $user, AttendanceRecord $record, ?array $window): int
    {
        if (! $window || ! $record->time_in) {
            return 0;
        }
        $grace = (int) ($user->profile?->late_grace_minutes ?? 0);
        $threshold = $window['start']->addMinutes($grace);
        $timeIn = CarbonImmutable::instance($record->time_in);

        return $timeIn->greaterThan($threshold) ? (int) $threshold->diffInMinutes($timeIn) : 0;
    }

    /**
     * Break minutes that fall inside the shift. The break is placed in the middle of the
     * scheduled window (8:00-5:00 with 60 min => 12:00-1:00), so a half day that never
     * reaches lunch is not charged for it. Without a schedule the whole break applies
     * only to shifts long enough to include one.
     */
    protected function breakOverlapMinutes(AttendanceRecord $record, ?array $window): int
    {
        $break = (int) $record->break_minutes;
        if ($break <= 0) {
            return 0;
        }

        $in = CarbonImmutable::instance($record->time_in);
        $out = CarbonImmutable::instance($record->time_out);

        if (! $window) {
            return (int) $in->diffInMinutes($out) > $break * 4 ? $break : 0;
        }

        $midpoint = $window['start']->addMinutes(intdiv((int) $window['start']->diffInMinutes($window['end']), 2));
        $breakStart = $midpoint->subMinutes(intdiv($break, 2));
        $breakEnd = $breakStart->addMinutes($break);

        $from = $in->greaterThan($breakStart) ? $in : $breakStart;
        $to = $out->lessThan($breakEnd) ? $out : $breakEnd;

        return $to->greaterThan($from) ? (int) $from->diffInMinutes($to) : 0;
    }

    protected function clearComputed(AttendanceRecord $record): void
    {
        $record->worked_minutes = 0;
        $record->regular_minutes = 0;
        $record->overtime_minutes = 0;
        $record->regular_amount = null;
        $record->overtime_amount = null;
        $record->salary_amount = null;
    }

    /**
     * Server time is authoritative. A client timestamp is only honoured for
     * offline synchronisation, and never in the future or older than the sync window.
     */
    protected function resolveOccurredAt(CarbonImmutable $now, ?string $occurredAt, string $source): CarbonImmutable
    {
        if (! $occurredAt || $source !== 'sync') {
            return $now;
        }

        try {
            $at = CarbonImmutable::parse($occurredAt)->utc();
        } catch (\Throwable) {
            throw ApiException::unprocessable('Invalid occurred_at timestamp.', ['occurred_at' => ['Invalid timestamp.']]);
        }

        $skew = (int) config('salary_tracker.clock_skew_minutes', 5);
        if ($at->greaterThan($now->addMinutes($skew))) {
            return $now;
        }

        $maxAge = (int) config('salary_tracker.max_sync_age_days', 3);
        if ($at->lessThan($now->subDays($maxAge))) {
            throw ApiException::unprocessable('This offline action is too old to synchronize. Please add it manually.', [], 'SYNC_TOO_OLD');
        }

        return $at;
    }

    /**
     * Accepts "HH:mm", "HH:mm:ss", or a full ISO datetime. Returns UTC.
     */
    protected function parseLocalTime(CarbonImmutable $day, ?string $value, string $tz): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $value)) {
            [$h, $m] = array_map('intval', explode(':', $value));

            return $day->setTime($h, $m)->utc();
        }

        try {
            return CarbonImmutable::parse($value, $tz)->utc();
        } catch (\Throwable) {
            throw ApiException::unprocessable('Invalid time value.', ['time' => ['Invalid time value.']]);
        }
    }
}
