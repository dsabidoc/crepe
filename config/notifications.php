<?php

return [
    'broadcast_enabled' => (bool) env('NOTIFICATION_BROADCAST_ENABLED', env('BROADCAST_CONNECTION') === 'reverb'),
    'broadcast_sync' => (bool) env('NOTIFICATION_BROADCAST_SYNC', false),
    'poll_interval_seconds' => 5,
    'reconcile_interval_seconds' => 60,
    'history_days' => 30,
    'retention_days' => 90,
    'reminder_hours' => 24,
];
