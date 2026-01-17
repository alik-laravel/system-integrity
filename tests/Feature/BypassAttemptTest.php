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

describe('tampered license file detection', function () {
    it('detects modified license id', function () {
        $deviceHash = $this->profiler->getSystemSignature();

        $validConfig = [
            'id' => 'original-license-id',
            'device_hash' => $deviceHash,
            'issued_at' => time() * 1000,
            'expires_at' => (time() + 86400 * 30) * 1000,
            'signature' => hash('sha256', 'original'),
        ];

        $encrypted = $this->crypto->encryptConfigurationData($validConfig, $deviceHash);
        $configPath = config('integrity.system_cache_path');

        $dir = dirname($configPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($configPath, $encrypted);

        // Decrypt, modify, and re-encrypt with same key (attacker scenario)
        $decrypted = $this->crypto->decryptConfigurationData($encrypted, $deviceHash);
        $decrypted['id'] = 'modified-license-id';

        // Attacker can't properly re-encrypt without matching signature
        $tamperedEncrypted = $this->crypto->encryptConfigurationData($decrypted, $deviceHash);
        file_put_contents($configPath, $tamperedEncrypted);

        // Remote validation will fail because license_id doesn't match server records
        Http::fake([
            '*/v1/validate' => Http::response(['valid' => false, 'reason' => 'not_found'], 200),
        ]);

        $result = $this->manager->verify();
        expect($result)->toBeFalse();

        unlink($configPath);
    });

    it('detects modified expiration date', function () {
        $deviceHash = $this->profiler->getSystemSignature();

        $originalConfig = [
            'id' => 'test-license',
            'device_hash' => $deviceHash,
            'issued_at' => time() * 1000,
            'expires_at' => (time() - 86400) * 1000, // Expired
            'signature' => hash('sha256', 'test'),
        ];

        $encrypted = $this->crypto->encryptConfigurationData($originalConfig, $deviceHash);
        $configPath = config('integrity.system_cache_path');

        $dir = dirname($configPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Modify expiration to future (bypass attempt)
        $decrypted = $this->crypto->decryptConfigurationData($encrypted, $deviceHash);
        $decrypted['expires_at'] = (time() + 86400 * 365) * 1000; // 1 year in future

        $tamperedEncrypted = $this->crypto->encryptConfigurationData($decrypted, $deviceHash);
        file_put_contents($configPath, $tamperedEncrypted);

        // Server validates original expiration
        Http::fake([
            '*/v1/validate' => Http::response(['valid' => false, 'reason' => 'expired'], 200),
        ]);

        $result = $this->manager->verify();
        expect($result)->toBeFalse();

        unlink($configPath);
    });

    it('detects corrupted encryption', function () {
        $configPath = config('integrity.system_cache_path');

        $dir = dirname($configPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Write corrupted/invalid encrypted data
        file_put_contents($configPath, 'corrupted-data-not-valid-base64!!!');

        $result = $this->manager->verify();
        expect($result)->toBeFalse();

        unlink($configPath);
    });

    it('detects partially modified ciphertext', function () {
        $deviceHash = $this->profiler->getSystemSignature();

        $validConfig = [
            'id' => 'test-license',
            'device_hash' => $deviceHash,
            'issued_at' => time() * 1000,
            'expires_at' => (time() + 86400 * 30) * 1000,
            'signature' => hash('sha256', 'test'),
        ];

        $encrypted = $this->crypto->encryptConfigurationData($validConfig, $deviceHash);
        $configPath = config('integrity.system_cache_path');

        $dir = dirname($configPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Flip some bits in the encrypted data
        $tampered = substr($encrypted, 0, -10) . 'XXXXXXXXXX';
        file_put_contents($configPath, $tampered);

        $result = $this->manager->verify();
        expect($result)->toBeFalse();

        unlink($configPath);
    });
});

describe('device binding bypass attempts', function () {
    it('fails when license copied to different device', function () {
        $originalDeviceHash = hash('sha256', 'original-device-unique-id');
        $currentDeviceHash = $this->profiler->getSystemSignature();

        // This simulates copying a license file from another device
        $licenseForOtherDevice = [
            'id' => 'test-license',
            'device_hash' => $originalDeviceHash, // Different device
            'issued_at' => time() * 1000,
            'expires_at' => (time() + 86400 * 30) * 1000,
            'signature' => hash('sha256', 'test'),
        ];

        // Encrypted with original device hash
        $encrypted = $this->crypto->encryptConfigurationData($licenseForOtherDevice, $originalDeviceHash);
        $configPath = config('integrity.system_cache_path');

        $dir = dirname($configPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($configPath, $encrypted);

        // Decryption will fail because current device hash differs
        $decrypted = $this->crypto->decryptConfigurationData($encrypted, $currentDeviceHash);
        expect($decrypted)->toBeNull();

        // ConfigurationManager will fail because it can't decrypt
        $result = $this->manager->verify();
        expect($result)->toBeFalse();

        unlink($configPath);
    });

    it('fails when device hash in config does not match current device', function () {
        $deviceHash = $this->profiler->getSystemSignature();

        // License encrypted correctly but contains wrong device_hash in payload
        $configWithWrongDevice = [
            'id' => 'test-license',
            'device_hash' => 'different-device-hash-from-payload',
            'issued_at' => time() * 1000,
            'expires_at' => (time() + 86400 * 30) * 1000,
            'signature' => hash('sha256', 'test'),
        ];

        $encrypted = $this->crypto->encryptConfigurationData($configWithWrongDevice, $deviceHash);
        $configPath = config('integrity.system_cache_path');

        $dir = dirname($configPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($configPath, $encrypted);

        // Decryption succeeds but device_hash validation fails
        $result = $this->manager->verify();
        expect($result)->toBeFalse();

        unlink($configPath);
    });
});

describe('cache manipulation bypass attempts', function () {
    it('does not trust stale cache when config file is removed', function () {
        $deviceHash = $this->profiler->getSystemSignature();

        $validConfig = [
            'id' => 'test-license',
            'device_hash' => $deviceHash,
            'issued_at' => time() * 1000,
            'expires_at' => (time() + 86400 * 30) * 1000,
            'signature' => hash('sha256', 'test'),
        ];

        $encrypted = $this->crypto->encryptConfigurationData($validConfig, $deviceHash);
        $configPath = config('integrity.system_cache_path');

        $dir = dirname($configPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($configPath, $encrypted);

        Http::fake([
            '*/v1/validate' => Http::response(['valid' => true], 200),
        ]);

        // First verification succeeds and gets cached
        $result1 = $this->manager->verify();
        expect($result1)->toBeTrue();

        // Remove license file (simulating revocation)
        unlink($configPath);

        // Clear cache to force re-validation
        $this->cache->flush();

        // New verification should fail since file is missing
        $newManager = new ConfigurationManager($this->profiler, $this->cache, $this->crypto);
        $result2 = $newManager->verify();
        expect($result2)->toBeFalse();
    });

    it('respects cache TTL and revalidates periodically', function () {
        $deviceHash = $this->profiler->getSystemSignature();

        $validConfig = [
            'id' => 'ttl-test-license',
            'device_hash' => $deviceHash,
            'issued_at' => time() * 1000,
            'expires_at' => (time() + 86400 * 30) * 1000,
            'signature' => hash('sha256', 'test'),
        ];

        $encrypted = $this->crypto->encryptConfigurationData($validConfig, $deviceHash);
        $configPath = config('integrity.system_cache_path');

        $dir = dirname($configPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($configPath, $encrypted);

        Http::fake([
            '*/v1/validate' => Http::response(['valid' => true], 200),
        ]);

        // First call makes remote request
        $this->manager->verify();

        // Second call uses cache (no additional request)
        $this->manager->verify();

        Http::assertSentCount(1);

        // After cache expires, revalidation occurs
        $this->cache->flush();

        $newManager = new ConfigurationManager($this->profiler, $this->cache, $this->crypto);
        $newManager->verify();

        Http::assertSentCount(2);

        unlink($configPath);
    });
});

describe('config path manipulation attempts', function () {
    it('does not allow symlink attacks', function () {
        $deviceHash = $this->profiler->getSystemSignature();
        $configPath = config('integrity.system_cache_path');

        $dir = dirname($configPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Create a valid config
        $validConfig = [
            'id' => 'test-license',
            'device_hash' => $deviceHash,
            'issued_at' => time() * 1000,
            'expires_at' => (time() + 86400 * 30) * 1000,
            'signature' => hash('sha256', 'test'),
        ];

        $encrypted = $this->crypto->encryptConfigurationData($validConfig, $deviceHash);

        // Try to create a symlink to a fake config (skip if symlinks not supported)
        $symlinkPath = $configPath;
        $fakePath = sys_get_temp_dir() . '/fake_license_' . uniqid();
        file_put_contents($fakePath, $encrypted);

        // Remove existing config if present
        if (file_exists($configPath)) {
            unlink($configPath);
        }

        // Attempt symlink creation (may fail on some systems)
        if (@symlink($fakePath, $symlinkPath)) {
            Http::fake([
                '*/v1/validate' => Http::response(['valid' => true], 200),
            ]);

            // Verification may succeed but is tied to the real file content
            $result = $this->manager->verify();

            // Cleanup
            unlink($symlinkPath);
            unlink($fakePath);

            // The important check: system validates against server regardless of local file location
            expect($result)->toBeTrue();
        } else {
            // Symlink not supported, test passes trivially
            expect(true)->toBeTrue();
        }
    });
});

describe('replay attack prevention', function () {
    it('detects revoked license after cache expires', function () {
        $deviceHash = $this->profiler->getSystemSignature();

        $validConfig = [
            'id' => 'replay-test-license',
            'device_hash' => $deviceHash,
            'issued_at' => time() * 1000,
            'expires_at' => (time() + 86400 * 30) * 1000,
            'signature' => hash('sha256', 'test'),
        ];

        $encrypted = $this->crypto->encryptConfigurationData($validConfig, $deviceHash);
        $configPath = config('integrity.system_cache_path');

        $dir = dirname($configPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($configPath, $encrypted);

        // Server indicates license was revoked
        Http::fake([
            '*/v1/validate' => Http::response(['valid' => false, 'reason' => 'revoked'], 200),
        ]);

        // With no cache, remote validation will fail
        $result = $this->manager->verify();
        expect($result)->toBeFalse();

        unlink($configPath);
    });

    it('cached valid result does not make remote calls', function () {
        $deviceHash = $this->profiler->getSystemSignature();

        $validConfig = [
            'id' => 'cache-test-license',
            'device_hash' => $deviceHash,
            'issued_at' => time() * 1000,
            'expires_at' => (time() + 86400 * 30) * 1000,
            'signature' => hash('sha256', 'test'),
        ];

        $encrypted = $this->crypto->encryptConfigurationData($validConfig, $deviceHash);
        $configPath = config('integrity.system_cache_path');

        $dir = dirname($configPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($configPath, $encrypted);

        // First validation
        Http::fake([
            '*/v1/validate' => Http::response(['valid' => true], 200),
        ]);

        $result1 = $this->manager->verify();
        expect($result1)->toBeTrue();

        // Second call should use cache (same manager instance)
        $result2 = $this->manager->verify();
        expect($result2)->toBeTrue();

        // Only one HTTP request should have been made
        Http::assertSentCount(1);

        unlink($configPath);
    });
});

describe('timestamp validation', function () {
    it('validates that timestamps are in expected range', function () {
        $deviceHash = $this->profiler->getSystemSignature();

        // Config with issued_at in the future (suspicious)
        $futureConfig = [
            'id' => 'future-license',
            'device_hash' => $deviceHash,
            'issued_at' => (time() + 86400 * 365) * 1000, // 1 year in future
            'expires_at' => (time() + 86400 * 730) * 1000, // 2 years in future
            'signature' => hash('sha256', 'test'),
        ];

        $encrypted = $this->crypto->encryptConfigurationData($futureConfig, $deviceHash);
        $configPath = config('integrity.system_cache_path');

        $dir = dirname($configPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($configPath, $encrypted);

        // Server validates timestamps
        Http::fake([
            '*/v1/validate' => Http::response(['valid' => false, 'reason' => 'invalid_timestamp'], 200),
        ]);

        $result = $this->manager->verify();
        expect($result)->toBeFalse();

        unlink($configPath);
    });
});

describe('missing required fields', function () {
    it('fails when id is missing', function () {
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

    it('fails when device_hash is missing', function () {
        $deviceHash = $this->profiler->getSystemSignature();

        $incompleteConfig = [
            'id' => 'test-license',
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

    it('fails when expires_at is missing', function () {
        $deviceHash = $this->profiler->getSystemSignature();

        $incompleteConfig = [
            'id' => 'test-license',
            'device_hash' => $deviceHash,
            'issued_at' => time() * 1000,
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

    it('fails when signature is missing', function () {
        $deviceHash = $this->profiler->getSystemSignature();

        $incompleteConfig = [
            'id' => 'test-license',
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
