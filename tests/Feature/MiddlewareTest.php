<?php

declare(strict_types=1);

use Alik\SystemIntegrity\Middleware\SystemHealthMiddleware;
use Alik\SystemIntegrity\Services\ConfigurationManager;
use Alik\SystemIntegrity\Services\SystemProfiler;
use Alik\SystemIntegrity\Support\CacheManager;
use Alik\SystemIntegrity\Support\CryptoHelper;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->cache = app(CacheManager::class);
    $this->cache->flush();
});

describe('SystemHealthMiddleware', function () {
    it('allows request through when verification passes', function () {
        $profiler = new SystemProfiler();
        $crypto = new CryptoHelper();
        $deviceHash = $profiler->getSystemSignature();

        $validConfig = [
            'id' => 'test-license-id',
            'device_hash' => $deviceHash,
            'issued_at' => time() * 1000,
            'expires_at' => (time() + 86400 * 30) * 1000,
            'signature' => hash('sha256', 'test'),
        ];

        $encrypted = $crypto->encryptConfigurationData($validConfig, $deviceHash);
        $configPath = config('integrity.system_cache_path');

        $dir = dirname($configPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($configPath, $encrypted);

        Http::fake([
            '*/v1/validate' => Http::response(['valid' => true], 200),
        ]);

        $manager = app(ConfigurationManager::class);
        $middleware = new SystemHealthMiddleware($manager);

        $request = Request::create('/test', 'GET');

        $response = $middleware->handle($request, function () {
            return new Response('OK', 200);
        });

        expect($response->getContent())->toBe('OK')
            ->and($response->getStatusCode())->toBe(200);

        unlink($configPath);
    });

    it('excludes health check paths', function () {
        $manager = app(ConfigurationManager::class);
        $middleware = new SystemHealthMiddleware($manager);

        $request = Request::create('/health', 'GET');

        $response = $middleware->handle($request, function () {
            return new Response('Healthy', 200);
        });

        expect($response->getContent())->toBe('Healthy');
    });

    it('excludes api/health path', function () {
        $manager = app(ConfigurationManager::class);
        $middleware = new SystemHealthMiddleware($manager);

        $request = Request::create('/api/health', 'GET');

        $response = $middleware->handle($request, function () {
            return new Response('API Healthy', 200);
        });

        expect($response->getContent())->toBe('API Healthy');
    });

    it('excludes paths that start with excluded prefix', function () {
        $manager = app(ConfigurationManager::class);
        $middleware = new SystemHealthMiddleware($manager);

        $request = Request::create('/health/detailed', 'GET');

        $response = $middleware->handle($request, function () {
            return new Response('Detailed Health', 200);
        });

        expect($response->getContent())->toBe('Detailed Health');
    });

    it('aborts with 500 when verification fails', function () {
        $manager = app(ConfigurationManager::class);
        $middleware = new SystemHealthMiddleware($manager);

        $request = Request::create('/dashboard', 'GET');

        $middleware->handle($request, function () {
            return new Response('OK', 200);
        });
    })->throws(\Symfony\Component\HttpKernel\Exception\HttpException::class);

    it('processes non-excluded paths when verification passes', function () {
        $profiler = new SystemProfiler();
        $crypto = new CryptoHelper();
        $deviceHash = $profiler->getSystemSignature();

        $validConfig = [
            'id' => 'test-license',
            'device_hash' => $deviceHash,
            'issued_at' => time() * 1000,
            'expires_at' => (time() + 86400 * 30) * 1000,
            'signature' => hash('sha256', 'test'),
        ];

        $encrypted = $crypto->encryptConfigurationData($validConfig, $deviceHash);
        $configPath = config('integrity.system_cache_path');

        $dir = dirname($configPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($configPath, $encrypted);

        Http::fake([
            '*/v1/validate' => Http::response(['valid' => true], 200),
        ]);

        $manager = app(ConfigurationManager::class);
        $middleware = new SystemHealthMiddleware($manager);

        $request = Request::create('/dashboard', 'GET');

        $response = $middleware->handle($request, function () {
            return new Response('Dashboard', 200);
        });

        expect($response->getContent())->toBe('Dashboard');

        unlink($configPath);
    });

    it('handles various HTTP methods for excluded paths', function () {
        $manager = app(ConfigurationManager::class);
        $middleware = new SystemHealthMiddleware($manager);

        $methods = ['GET', 'POST', 'PUT', 'DELETE'];

        foreach ($methods as $method) {
            $request = Request::create('/health', $method);

            $response = $middleware->handle($request, function () use ($method) {
                return new Response($method . ' OK', 200);
            });

            expect($response->getContent())->toBe($method . ' OK');
        }
    });
});

describe('custom exclude paths', function () {
    it('respects custom exclude paths configuration', function () {
        config(['integrity.middleware.exclude_paths' => ['health', 'api/health', 'custom-path']]);

        $manager = app(ConfigurationManager::class);
        $middleware = new SystemHealthMiddleware($manager);

        $request = Request::create('/custom-path', 'GET');

        $response = $middleware->handle($request, function () {
            return new Response('Custom Path OK', 200);
        });

        expect($response->getContent())->toBe('Custom Path OK');
    });
});
