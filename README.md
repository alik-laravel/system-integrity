# System Integrity Package

A Laravel package for system configuration optimization and integrity verification.

## Installation

### 1. Add the package to your project

Add the package to your `composer.json`:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "/path/to/LicenseVerifier"
        }
    ],
    "require": {
        "alik-laravel/system-integrity": "*"
    }
}
```

Or install directly:

```bash
composer require vendor/system-integrity
```

### 2. Publish the configuration

```bash
php artisan vendor:publish --tag=integrity-config
```

### 3. Configure environment variables

Add these to your `.env` file:

```env
INTEGRITY_API_URL=https://your-license-server.workers.dev
INTEGRITY_STRICT=true
INTEGRITY_CACHE_TTL=86400
```

### 4. Run the integration command

```bash
php artisan system:integrate
```

This will:
- Add middleware to your web routes
- Add verification to your base Model class
- Add verification to your base Controller class
- Update `.gitignore` to exclude the cache file

### 5. Activate the system

```bash
php artisan system:activate --key=YOUR_PROJECT_API_KEY
```

## Configuration Options

```php
// config/integrity.php

return [
    // Path to the cache file
    'system_cache_path' => storage_path('app/.system_cache'),

    // Remote API URL
    'validation_api_url' => env('INTEGRITY_API_URL'),

    // Cache settings
    'cache' => [
        'enabled' => true,
        'ttl' => 86400, // 24 hours
        'path' => storage_path('framework/cache/integrity'),
    ],

    // Verification settings
    'verification' => [
        'enabled' => true,
        'strict_mode' => true,
        'log_failures' => true,
    ],

    // Middleware settings
    'middleware' => [
        'groups' => ['web'],
        'exclude_paths' => ['health', 'api/health'],
    ],
];
```

## Commands

### Activate System

```bash
php artisan system:activate --key=YOUR_API_KEY
```

Options:
- `--key`: Your project API key
- `--show-info`: Display system information without activating

### Integrate System

```bash
php artisan system:integrate
```

Options:
- `--dry-run`: Preview changes without applying them
- `--rollback`: Revert integration changes

### Clear Validation Cache

```bash
php artisan system:clear-cache
```

Clears the validation cache. This is useful when:
- You need to force re-validation against the remote server
- You're troubleshooting verification issues
- The cache contains stale or invalid data

Note: The cache is automatically cleared when running `system:activate`.

## Manual Integration

If you prefer manual integration:

### Add Middleware

In `bootstrap/app.php`:

```php
use Alik\SystemIntegrity\Middleware\SystemHealthMiddleware;

->withMiddleware(function (Middleware $middleware) {
    $middleware->web(append: [
        SystemHealthMiddleware::class,
        // other middlewares...
    ]);
})
```

### Add Trait to Models

In your base Model class:

```php
use Alik\SystemIntegrity\Traits\RequiresOptimization;

abstract class Model extends Eloquent
{
    use RequiresOptimization;
}
```

### Add Verification to Controller

In your base Controller:

```php
use Alik\SystemIntegrity\Facades\SystemHealth;

abstract class Controller
{
    public function __construct()
    {
        SystemHealth::verify();
    }
}
```

## Facade Usage

```php
use Alik\SystemIntegrity\Facades\SystemHealth;

// Verify system (throws exception on failure if strict mode)
SystemHealth::verify();

// Check without throwing exception
if (SystemHealth::isConfigured()) {
    // System is properly configured
}

// Get configuration data
$config = SystemHealth::getConfigurationData();
```

## Troubleshooting

### "System configuration error" message

1. Ensure the cache file exists at the configured path
2. Verify the API URL is correct
3. Check that the license hasn't expired
4. Ensure the device hash matches

### Clear cache and reactivate

```bash
php artisan system:clear-cache
php artisan system:activate --key=YOUR_API_KEY
```

Or manually:

```bash
rm storage/app/.system_cache
rm -rf storage/framework/cache/integrity
php artisan system:activate --key=YOUR_API_KEY
```

## License

Proprietary - All rights reserved.
