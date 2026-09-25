<?php

return [
    // Default timezone used for day boundaries, schedules and salary periods.
    'timezone' => env('SALARY_TRACKER_TIMEZONE', 'Asia/Manila'),

    'currency' => 'PHP',

    // Default weekly schedule applied to new users (0 = Sunday ... 6 = Saturday).
    'default_schedule' => [
        0 => ['is_working_day' => false, 'start_time' => null, 'end_time' => null, 'break_minutes' => 0],
        1 => ['is_working_day' => true, 'start_time' => '08:00', 'end_time' => '17:00', 'break_minutes' => 60],
        2 => ['is_working_day' => true, 'start_time' => '08:00', 'end_time' => '17:00', 'break_minutes' => 60],
        3 => ['is_working_day' => true, 'start_time' => '08:00', 'end_time' => '17:00', 'break_minutes' => 60],
        4 => ['is_working_day' => true, 'start_time' => '08:00', 'end_time' => '17:00', 'break_minutes' => 60],
        5 => ['is_working_day' => true, 'start_time' => '08:00', 'end_time' => '17:00', 'break_minutes' => 60],
        6 => ['is_working_day' => true, 'start_time' => '08:00', 'end_time' => '17:00', 'break_minutes' => 60],
    ],

    // How many hours after the scheduled end (or after time-in when unscheduled)
    // an open attendance record is considered a forgotten time-out.
    'stale_open_hours' => 12,

    // Offline actions older than this are rejected during synchronization.
    'max_sync_age_days' => 3,

    // Allowed clock skew (minutes) for client supplied timestamps.
    'clock_skew_minutes' => 5,

    // Number of upcoming days included in the notification plan.
    'notification_plan_days' => 14,
];
