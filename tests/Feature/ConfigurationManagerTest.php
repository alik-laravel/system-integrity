<?php

declare(strict_types=1);

use Alik\SystemIntegrity\Services\ConfigurationManager;
use Alik\SystemIntegrity\Services\SystemProfiler;
use Alik\SystemIntegrity\Support\CacheManager;
use Alik\SystemIntegrity\Support\CryptoHelper;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->profiler = new SystemProfiler();
    $this->cache = new CacheManager();
    $this->crypto = new CryptoHelper();

    $this->manager = new ConfigurationManager(
        $this->profiler,
        $this->cache,
        $this->crypto
    );

    $this->cache->flush();
});

describe('verification without config file', function () {
    it('returns false when configuration file is missing', function () {
        $result = $this->manager->verify();

        expect($result)->toBeFalse();
    });

    it('isConfigured returns false when file is missing', function () {
        $result = $this->manager->isConfigured();

        expect($result)->toBeFalse();
    });

    it('getConfigurationData returns null when file is missing', function () {
        $result = $this->manager->getConfigurationData();

        expect($result)->toBeNull();
    });
});

describe('verification with valid config file', function () {
    beforeEach(function () {
        $deviceHash = $this->profiler->getSystemSignature();

        $this->validConfigData = [
            'id' => 'test-license-id-' . uniqid(),
            'device_hash' => $deviceHash,
            'issued_at' => time() * 1000,
            'expires_at' => (time() + 86400 * 30) * 1000,
            'signature' => hash('sha256', 'test-signature'),
        ];

        $encrypted = $this->crypto->encryptConfigurationData($this->validConfigData, $deviceHash);
        $configPath = config('integrity.system_cache_path');

        $dir = dirname($configPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($configPath, $encrypted);
    });

    afterEach(function () {
        $configPath = config('integrity.system_cache_path');
        if (file_exists($configPath)) {
            unlink($configPath);
        }
    });

    it('loads and decrypts configuration file', function () {
        $data = $this->manager->getConfigurationData();

        expect($data)->toBeArray()
            ->and($data['id'])->toBe($this->validConfigData['id'])
            ->and($data['device_hash'])->toBe($this->validConfigData['device_hash']);
    });

    it('validates local configuration successfully', function () {
        Http::fake([
            '*/v1/validate' => Http::response(['valid' => true, 'expires_at' => time() * 1000 + 86400000], 200),
        ]);

        $result = $this->manager->verify();

        expect($result)->toBeTrue();
    });

    it('caches successful validation result', function () {
        Http::fake([
            '*/v1/validate' => Http::response(['valid' => true], 200),
        ]);

        $this->manager->verify();

        $newManager = new ConfigurationManager($this->profiler, $this->cache, $this->crypto);
        $result = $newManager->verify();

        expect($result)->toBeTrue();
    });

    it('returns cached result on subsequent calls', function () {
        Http::fake([
            '*/v1/validate' => Http::response(['valid' => true], 200),
        ]);

        $result1 = $this->manager->verify();
        $result2 = $this->manager->verify();

        expect($result1)->toBeTrue()
            ->and($result2)->toBeTrue();

        Http::assertSentCount(1);
    });
});

describe('verification with expired config', function () {
    it('returns false when configuration is expired', function () {
        $deviceHash = $this->profiler->getSystemSignature();

        $expiredConfig = [
            'id' => 'expired-license',
            'device_hash' => $deviceHash,
            'issued_at' => (time() - 86400 * 60) * 1000,
            'expires_at' => (time() - 86400) * 1000,
            'signature' => hash('sha256', 'test'),
        ];

        $encrypted = $this->crypto->encryptConfigurationData($expiredConfig, $deviceHash);
        $configPath = config('integrity.system_cache_path');

        $dir = dirname($configPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($configPath, $encrypted);

        $result = $this->manager->verify();

        expect($result)->toBeFalse();

        unlink($configPath);
    });
});

describe('verification with wrong device hash', function () {
    it('returns false when device hash does not match', function () {
        $wrongDeviceHash = hash('sha256', 'wrong-device');
        $currentDeviceHash = $this->profiler->getSystemSignature();

        $configData = [
            'id' => 'test-license',
            'device_hash' => $wrongDeviceHash,
            'issued_at' => time() * 1000,
            'expires_at' => (time() + 86400) * 1000,
            'signature' => hash('sha256', 'test'),
        ];

        $encrypted = $this->crypto->encryptConfigurationData($configData, $currentDeviceHash);
        $configPath = config('integrity.system_cache_path');

        $dir = dirname($configPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($configPath, $encrypted);

        $result = $this->manager->verify();

        expect($result)->toBeFalse();

        unlink($configPath);
    });
});

describe('remote validation', function () {
    beforeEach(function () {
        $deviceHash = $this->profiler->getSystemSignature();

        $this->validConfig = [
            'id' => 'remote-test-license',
            'device_hash' => $deviceHash,
            'issued_at' => time() * 1000,
            'expires_at' => (time() + 86400 * 30) * 1000,
            'signature' => hash('sha256', 'test'),
        ];

        $encrypted = $this->crypto->encryptConfigurationData($this->validConfig, $deviceHash);
        $configPath = config('integrity.system_cache_path');

        $dir = dirname($configPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($configPath, $encrypted);
    });

    afterEach(function () {
        $configPath = config('integrity.system_cache_path');
        if (file_exists($configPath)) {
            unlink($configPath);
        }
    });

    it('returns false when remote validation fails', function () {
        Http::fake([
            '*/v1/validate' => Http::response(['valid' => false, 'reason' => 'License revoked'], 200),
        ]);

        $result = $this->manager->verify();

        expect($result)->toBeFalse();
    });

    it('returns false when remote server returns error', function () {
        Http::fake([
            '*/v1/validate' => Http::response(['error' => 'Server error'], 500),
        ]);

        $result = $this->manager->verify();

        expect($result)->toBeFalse();
    });

    it('returns false when remote server is unreachable', function () {
        Http::fake([
            '*/v1/validate' => fn () => throw new \Exception('Connection failed'),
        ]);

        $result = $this->manager->verify();

        expect($result)->toBeFalse();
    });

    it('sends correct data to remote server', function () {
        Http::fake([
            '*/v1/validate' => Http::response(['valid' => true], 200),
        ]);

        $this->manager->verify();

        Http::assertSent(function ($request) {
            return $request->url() === config('integrity.validation_api_url') . '/v1/validate'
                && $request['license_id'] === $this->validConfig['id']
                && $request['device_hash'] === $this->profiler->getSystemSignature()
                && isset($request['timestamp']);
        });
    });
});

describe('cache invalidation', function () {
    it('respects cache TTL', function () {
        Http::fake([
            '*/v1/validate' => Http::response(['valid' => true], 200),
        ]);

        $deviceHash = $this->profiler->getSystemSignature();

        $configData = [
            'id' => 'cache-ttl-test',
            'device_hash' => $deviceHash,
            'issued_at' => time() * 1000,
            'expires_at' => (time() + 86400) * 1000,
            'signature' => hash('sha256', 'test'),
        ];

        $encrypted = $this->crypto->encryptConfigurationData($configData, $deviceHash);
        $configPath = config('integrity.system_cache_path');

        $dir = dirname($configPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($configPath, $encrypted);

        $this->manager->verify();

        $this->cache->flush();

        $newManager = new ConfigurationManager($this->profiler, $this->cache, $this->crypto);
        $newManager->verify();

        Http::assertSentCount(2);

        unlink($configPath);
    });
});

describe('missing required fields', function () {
    it('returns false when config is missing id', function () {
        $deviceHash = $this->profiler->getSystemSignature();

        $incompleteConfig = [
            'device_hash' => $deviceHash,
            'issued_at' => time() * 1000,
            'expires_at' => (time() + 86400) * 1000,
            'signature' => hash('sha256', 'test'),
        ];

        $encrypted = $this->crypto->encryptConfigurationData($incompleteConfig, $deviceHash);
        $configPath = config('integrity.system_cache_path');

        $dir = dirname($configPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($configPath, $encrypted);

        $result = $this->manager->verify();

        expect($result)->toBeFalse();

        unlink($configPath);
    });

    it('returns false when config is missing signature', function () {
        $deviceHash = $this->profiler->getSystemSignature();

        $incompleteConfig = [
            'id' => 'test-id',
            'device_hash' => $deviceHash,
            'issued_at' => time() * 1000,
            'expires_at' => (time() + 86400) * 1000,
        ];

        $encrypted = $this->crypto->encryptConfigurationData($incompleteConfig, $deviceHash);
        $configPath = config('integrity.system_cache_path');

        $dir = dirname($configPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($configPath, $encrypted);

        $result = $this->manager->verify();

        expect($result)->toBeFalse();

        unlink($configPath);
    });
});
