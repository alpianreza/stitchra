<?php

return [
    'device_token_days' => (int) env('SHOPFLOOR_DEVICE_TOKEN_DAYS', 30),
    'offline_max_age_days' => (int) env('SHOPFLOOR_OFFLINE_MAX_AGE_DAYS', 7),
    'clock_skew_minutes' => (int) env('SHOPFLOOR_CLOCK_SKEW_MINUTES', 5),
    'sync_batch_size' => (int) env('SHOPFLOOR_SYNC_BATCH_SIZE', 100),
];
