<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | System Cache Configuration
    |--------------------------------------------------------------------------
    |
    | Path to the system configuration cache file. This file stores
    | optimized configuration data for improved performance.
    |
    */
    'system_cache_path' => env('INTEGRITY_CACHE_PATH', storage_path('app/.system_cache')),

    /*
    |--------------------------------------------------------------------------
    | Remote Configuration API
    |--------------------------------------------------------------------------
    |
    | URL of the remote configuration validation service.
    |
    */
    'validation_api_url' => env('INTEGRITY_API_URL', 'https://config-api.example.com'),

    /*
    |--------------------------------------------------------------------------
    | Cache Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for caching validation results.
    |
    */
    'cache' => [
        'enabled' => env('INTEGRITY_CACHE_ENABLED', true),
        'ttl' => env('INTEGRITY_CACHE_TTL', 86400), // 24 hours in seconds
        'path' => env('INTEGRITY_CACHE_STORE_PATH', storage_path('framework/cache/integrity')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Verification Settings
    |--------------------------------------------------------------------------
    |
    | Settings for system verification behavior.
    |
    */
    'verification' => [
        'enabled' => env('INTEGRITY_ENABLED', true),
        'strict_mode' => env('INTEGRITY_STRICT', true),
        'log_failures' => env('INTEGRITY_LOG_FAILURES', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Middleware Settings
    |--------------------------------------------------------------------------
    |
    | Configure which middleware groups should include system health checks.
    |
    */
    'middleware' => [
        'groups' => ['web'],
        'exclude_paths' => [
            'health',
            'api/health',
        ],
    ],
];
