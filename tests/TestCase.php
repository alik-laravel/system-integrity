<?php

declare(strict_types=1);

namespace Alik\SystemIntegrity\Tests;

use Alik\SystemIntegrity\Providers\IntegrityServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTempDirectory();
    }

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectory();

        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [
            IntegrityServiceProvider::class,
        ];
    }

    protected function getPackageAliases($app): array
    {
        return [
            'SystemHealth' => \Alik\SystemIntegrity\Facades\SystemHealth::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));

        $app['config']->set('integrity.system_cache_path', $this->getTempPath('.system_cache'));
        $app['config']->set('integrity.validation_api_url', 'https://license-test.example.com');
        $app['config']->set('integrity.encryption_key', 'test-encryption-key-32-chars!!');
        $app['config']->set('integrity.cache.enabled', true);
        $app['config']->set('integrity.cache.ttl', 3600);
        $app['config']->set('integrity.cache.path', $this->getTempPath('cache'));
        $app['config']->set('integrity.verification.strict_mode', false);
        $app['config']->set('integrity.verification.log_failures', false);
        $app['config']->set('integrity.middleware.exclude_paths', ['health', 'api/health']);
    }

    protected function getTempPath(string $path = ''): string
    {
        $base = sys_get_temp_dir() . '/system-integrity-tests';

        return $path ? $base . '/' . $path : $base;
    }

    protected function setUpTempDirectory(): void
    {
        $path = $this->getTempPath();
        if (! is_dir($path)) {
            mkdir($path, 0755, true);
        }

        $cachePath = $this->getTempPath('cache');
        if (! is_dir($cachePath)) {
            mkdir($cachePath, 0755, true);
        }
    }

    protected function cleanUpTempDirectory(): void
    {
        $path = $this->getTempPath();
        if (is_dir($path)) {
            $this->deleteDirectory($path);
        }
    }

    protected function deleteDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $filePath = $dir . '/' . $file;
            if (is_dir($filePath)) {
                $this->deleteDirectory($filePath);
            } else {
                unlink($filePath);
            }
        }
        rmdir($dir);
    }
}
