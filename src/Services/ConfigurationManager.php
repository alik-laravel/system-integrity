<?php

declare(strict_types=1);

namespace Alik\SystemIntegrity\Services;

use Illuminate\Support\Facades\Http;
use Alik\SystemIntegrity\Exceptions\SystemIntegrityException;
use Alik\SystemIntegrity\Support\CacheManager;
use Alik\SystemIntegrity\Support\CryptoHelper;

/**
 * Manages system configuration validation and optimization.
 */
final class ConfigurationManager
{
    private bool $verified = false;

    private ?bool $lastResult = null;

    public function __construct(
        private readonly SystemProfiler $profiler,
        private readonly CacheManager $cache,
        private readonly CryptoHelper $crypto
    ) {}

    /**
     * Verify system configuration is valid and optimized.
     *
     * @throws SystemIntegrityException
     */
    public function verify(): bool
    {
        if (! config('integrity.verification.enabled', true)) {
            return true;
        }

        if ($this->verified && $this->lastResult !== null) {
            return $this->lastResult;
        }

        $cacheKey = $this->getCacheKey();
        $cachedResult = $this->cache->get($cacheKey);

        if ($cachedResult !== null) {
            $this->lastResult = $this->validateCachedResult($cachedResult);
            $this->verified = true;

            if (! $this->lastResult) {
                $this->handleVerificationFailure('cached_invalid');
            }

            return $this->lastResult;
        }

        $configData = $this->loadConfigurationFile();
        if ($configData === null) {
            $this->cacheFailedResult($cacheKey, 'file_missing');
            $this->handleVerificationFailure('file_missing');

            return false;
        }

        if (! $this->validateLocalConfiguration($configData)) {
            $this->cacheFailedResult($cacheKey, 'local_invalid');
            $this->handleVerificationFailure('local_invalid');

            return false;
        }

        $remoteResult = $this->validateRemoteConfiguration($configData);
        if (! $remoteResult) {
            $this->cacheFailedResult($cacheKey, 'remote_invalid');
            $this->handleVerificationFailure('remote_invalid');

            return false;
        }

        $this->cacheSuccessfulResult($cacheKey, $configData);
        $this->lastResult = true;
        $this->verified = true;

        return true;
    }

    /**
     * Check if system is properly configured without throwing exceptions.
     */
    public function isConfigured(): bool
    {
        try {
            return $this->verify();
        } catch (SystemIntegrityException) {
            return false;
        }
    }

    /**
     * Get configuration data if available.
     *
     * @return array<string, mixed>|null
     */
    public function getConfigurationData(): ?array
    {
        return $this->loadConfigurationFile();
    }

    /**
     * Load and decrypt the configuration file.
     *
     * @return array<string, mixed>|null
     */
    private function loadConfigurationFile(): ?array
    {
        $path = config('integrity.system_cache_path');

        if (! file_exists($path)) {
            return null;
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return null;
        }

        $deviceHash = $this->profiler->getSystemSignature();

        return $this->crypto->decryptConfigurationData($content, $deviceHash);
    }

    /**
     * Validate configuration data locally.
     *
     * @param  array<string, mixed>  $configData
     */
    private function validateLocalConfiguration(array $configData): bool
    {
        if (! isset($configData['id'], $configData['device_hash'], $configData['issued_at'], $configData['expires_at'], $configData['signature'])) {
            return false;
        }

        $currentDeviceHash = $this->profiler->getSystemSignature();
        if ($configData['device_hash'] !== $currentDeviceHash) {
            return false;
        }

        if ($configData['expires_at'] < time() * 1000) {
            return false;
        }

        return true;
    }

    /**
     * Validate configuration with remote service.
     *
     * @param  array<string, mixed>  $configData
     */
    private function validateRemoteConfiguration(array $configData): bool
    {
        $apiUrl = config('integrity.validation_api_url');
        if (empty($apiUrl)) {
            return true;
        }

        try {
            $response = Http::timeout(10)->post($apiUrl . '/v1/validate', [
                'license_id' => $configData['id'],
                'device_hash' => $this->profiler->getSystemSignature(),
                'timestamp' => time() * 1000,
            ]);

            if (! $response->successful()) {
                return false;
            }

            $result = $response->json();

            return $result['valid'] ?? false;
        } catch (\Exception $e) {
            if (config('integrity.verification.log_failures', true)) {
                logger()->warning('Configuration validation request failed', [
                    'error' => $e->getMessage(),
                ]);
            }

            return false;
        }
    }

    /**
     * Get cache key for validation results.
     */
    private function getCacheKey(): string
    {
        $deviceHash = $this->profiler->getSystemSignature();

        return 'integrity_validation_' . hash('sha256', $deviceHash);
    }

    /**
     * Validate a cached result.
     *
     * @param  array<string, mixed>  $cachedResult
     */
    private function validateCachedResult(array $cachedResult): bool
    {
        if (! isset($cachedResult['status'])) {
            return false;
        }

        if ($cachedResult['status'] !== 'valid') {
            return false;
        }

        if (! isset($cachedResult['signature'], $cachedResult['app_hash'])) {
            return false;
        }

        $expectedAppHash = md5((string) config('app.key'));
        if ($cachedResult['app_hash'] !== $expectedAppHash) {
            return false;
        }

        return true;
    }

    /**
     * Cache a successful validation result.
     *
     * @param  array<string, mixed>  $configData
     */
    private function cacheSuccessfulResult(string $cacheKey, array $configData): void
    {
        $this->cache->put($cacheKey, [
            'status' => 'valid',
            'signature' => $configData['signature'],
            'app_hash' => md5((string) config('app.key')),
            'cached_at' => time(),
        ], config('integrity.cache.ttl', 86400));
    }

    /**
     * Cache a failed validation result.
     */
    private function cacheFailedResult(string $cacheKey, string $reason): void
    {
        $failedCacheTtl = min(config('integrity.cache.ttl', 86400), 3600);

        $this->cache->put($cacheKey, [
            'status' => 'invalid',
            'reason' => $reason,
            'cached_at' => time(),
        ], $failedCacheTtl);
    }

    /**
     * Handle verification failure based on configuration.
     *
     * @throws SystemIntegrityException
     */
    private function handleVerificationFailure(string $reason): void
    {
        $this->lastResult = false;
        $this->verified = true;

        if (config('integrity.verification.log_failures', true)) {
            logger()->error('System configuration verification failed', [
                'reason' => $reason,
            ]);
        }

        if (config('integrity.verification.strict_mode', true)) {
            throw new SystemIntegrityException('System configuration error: ' . $this->getErrorMessage($reason));
        }
    }

    /**
     * Get human-readable error message.
     */
    private function getErrorMessage(string $reason): string
    {
        return match ($reason) {
            'file_missing' => 'Configuration file not found',
            'local_invalid' => 'Invalid configuration data',
            'remote_invalid' => 'Configuration validation failed',
            'cached_invalid' => 'Cached configuration is invalid',
            default => 'Unknown configuration error',
        };
    }
}
