<?php

return [
    'default' => env('CACHE_STORE', 'redis'),
    'stores' => [
        'redis' => [
            'driver' => 'redis',
            'connection' => 'cache',
            'lock_connection' => 'default',
        ],
        'array' => ['driver' => 'array', 'serialize' => false],
    ],
    'prefix' => env('CACHE_PREFIX', 'stitchra_cache'),
];
