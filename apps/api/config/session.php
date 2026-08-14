<?php

return [
    'default' => env('SESSION_DRIVER', 'redis'),
    'lifetime' => (int) env('SESSION_LIFETIME', 120),
    'expire_on_close' => false,
    'encrypt' => true,
    'connection' => env('SESSION_CONNECTION', 'default'),
    'store' => env('SESSION_STORE'),
    'cookie' => env('SESSION_COOKIE', 'stitchra_session'),
    'path' => '/',
    'domain' => env('SESSION_DOMAIN'),
    'secure' => env('SESSION_SECURE_COOKIE', false),
    'http_only' => true,
    'same_site' => 'lax',
];
