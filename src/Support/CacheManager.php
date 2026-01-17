<?php

declare(strict_types=1);

namespace Alik\SystemIntegrity\Support;

/**
 * File-based cache manager for configuration validation.
 */
final class CacheManager
{
    private ?string $cachePath = null;

    /**
     * Get cached data.
     *
     * @return array<string, mixed>|null
     */
    public function get(string $key): ?array
    {
        $filePath = $this->getCacheFilePath($key);

        if (! file_exists($filePath)) {
            return null;
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);
        if (! is_array($data)) {
            return null;
        }

        if (isset($data['expires_at']) && $data['expires_at'] < time()) {
            $this->forget($key);

            return null;
        }

        return $data['value'] ?? null;
    }

    /**
     * Store data in cache.
     *
     * @param  array<string, mixed>  $value
     */
    public function put(string $key, array $value, int $ttl): bool
    {
        $this->ensureCacheDirectoryExists();

        $filePath = $this->getCacheFilePath($key);
        $data = [
            'value' => $value,
            'expires_at' => time() + $ttl,
            'created_at' => time(),
        ];

        $result = file_put_contents($filePath, json_encode($data), LOCK_EX);

        return $result !== false;
    }

    /**
     * Remove cached data.
     */
    public function forget(string $key): bool
    {
        $filePath = $this->getCacheFilePath($key);

        if (file_exists($filePath)) {
            return unlink($filePath);
        }

        return true;
    }

    /**
     * Clear all cached data.
     */
    public function flush(): bool
    {
        $path = $this->getCachePath();

        if (! is_dir($path)) {
            return true;
        }

        $files = glob($path . '/*.cache');
        if ($files === false) {
            return false;
        }

        foreach ($files as $file) {
            unlink($file);
        }

        return true;
    }

    /**
     * Check if cache entry exists and is valid.
     */
    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /**
     * Get cache file path for a key.
     */
    private function getCacheFilePath(string $key): string
    {
        $hash = hash('sha256', $key);

        return $this->getCachePath() . '/' . $hash . '.cache';
    }

    /**
     * Get the cache directory path.
     */
    private function getCachePath(): string
    {
        if ($this->cachePath === null) {
            $this->cachePath = config('integrity.cache.path', storage_path('framework/cache/integrity'));
        }

        return $this->cachePath;
    }

    /**
     * Ensure the cache directory exists.
     */
    private function ensureCacheDirectoryExists(): void
    {
        $path = $this->getCachePath();

        if (! is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }
}
