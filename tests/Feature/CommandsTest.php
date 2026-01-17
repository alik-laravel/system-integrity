<?php

declare(strict_types=1);

use Alik\SystemIntegrity\Support\CacheManager;
use Illuminate\Support\Facades\Http;

describe('system:activate command', function () {
    it('shows system info with --show-info option', function () {
        $this->artisan('system:activate', ['--show-info' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('System Information')
            ->expectsOutputToContain('Device Hash')
            ->expectsOutputToContain('Hostname')
            ->expectsOutputToContain('PHP Version');
    });

    it('requires API key', function () {
        $this->artisan('system:activate')
            ->expectsQuestion('Enter API key', '')
            ->assertFailed()
            ->expectsOutputToContain('API key is required');
    });

    it('activates successfully with valid API key', function () {
        Http::fake([
            '*/v1/activate' => Http::response('encrypted-config-data', 200),
        ]);

        $this->artisan('system:activate', ['--key' => 'pk_test_api_key'])
            ->assertSuccessful()
            ->expectsOutputToContain('Collecting system information')
            ->expectsOutputToContain('Activating configuration')
            ->expectsOutputToContain('System configuration activated successfully');
    });

    it('handles activation failure', function () {
        Http::fake([
            '*/v1/activate' => Http::response(['error' => 'Invalid API key'], 401),
        ]);

        $this->artisan('system:activate', ['--key' => 'pk_invalid_key'])
            ->assertFailed()
            ->expectsOutputToContain('Activation failed');
    });

    it('handles network errors', function () {
        Http::fake([
            '*/v1/activate' => fn () => throw new \Exception('Connection failed'),
        ]);

        $this->artisan('system:activate', ['--key' => 'pk_test_key'])
            ->assertFailed()
            ->expectsOutputToContain('Activation failed');
    });

    it('saves configuration file on success', function () {
        Http::fake([
            '*/v1/activate' => Http::response('test-encrypted-data', 200),
        ]);

        $this->artisan('system:activate', ['--key' => 'pk_test_key'])
            ->assertSuccessful();

        $configPath = config('integrity.system_cache_path');

        expect(file_exists($configPath))->toBeTrue()
            ->and(file_get_contents($configPath))->toBe('test-encrypted-data');

        unlink($configPath);
    });

    it('clears cache after successful activation', function () {
        $cache = app(CacheManager::class);
        $cache->put('test-key', ['data' => 'test'], 3600);

        expect($cache->has('test-key'))->toBeTrue();

        Http::fake([
            '*/v1/activate' => Http::response('encrypted-data', 200),
        ]);

        $this->artisan('system:activate', ['--key' => 'pk_test_key'])
            ->assertSuccessful();

        expect($cache->has('test-key'))->toBeFalse();

        $configPath = config('integrity.system_cache_path');
        if (file_exists($configPath)) {
            unlink($configPath);
        }
    });

    it('accepts API key from prompt', function () {
        Http::fake([
            '*/v1/activate' => Http::response('data', 200),
        ]);

        $this->artisan('system:activate')
            ->expectsQuestion('Enter API key', 'pk_prompted_key')
            ->assertSuccessful();

        Http::assertSent(function ($request) {
            return $request->hasHeader('X-API-Key', 'pk_prompted_key');
        });

        $configPath = config('integrity.system_cache_path');
        if (file_exists($configPath)) {
            unlink($configPath);
        }
    });
});

describe('system:clear-cache command', function () {
    it('clears the validation cache', function () {
        $cache = app(CacheManager::class);

        $cache->put('cache-key-1', ['data' => '1'], 3600);
        $cache->put('cache-key-2', ['data' => '2'], 3600);

        expect($cache->has('cache-key-1'))->toBeTrue()
            ->and($cache->has('cache-key-2'))->toBeTrue();

        $this->artisan('system:clear-cache')
            ->assertSuccessful()
            ->expectsOutputToContain('Validation cache cleared successfully');

        expect($cache->has('cache-key-1'))->toBeFalse()
            ->and($cache->has('cache-key-2'))->toBeFalse();
    });

    it('succeeds even when cache is empty', function () {
        $cache = app(CacheManager::class);
        $cache->flush();

        $this->artisan('system:clear-cache')
            ->assertSuccessful()
            ->expectsOutputToContain('Validation cache cleared successfully');
    });
});
