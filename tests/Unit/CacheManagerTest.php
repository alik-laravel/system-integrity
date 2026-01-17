<?php

declare(strict_types=1);

use Alik\SystemIntegrity\Support\CacheManager;

beforeEach(function () {
    $this->cache = new CacheManager();
});

describe('basic cache operations', function () {
    it('stores and retrieves data', function () {
        $key = 'test-key';
        $value = ['status' => 'valid', 'data' => 'test'];

        $result = $this->cache->put($key, $value, 3600);
        expect($result)->toBeTrue();

        $retrieved = $this->cache->get($key);
        expect($retrieved)->toBe($value);
    });

    it('returns null for non-existent keys', function () {
        $result = $this->cache->get('non-existent-key');

        expect($result)->toBeNull();
    });

    it('checks if key exists with has method', function () {
        $key = 'existence-test';

        expect($this->cache->has($key))->toBeFalse();

        $this->cache->put($key, ['test' => true], 3600);

        expect($this->cache->has($key))->toBeTrue();
    });

    it('forgets cached data', function () {
        $key = 'forget-test';
        $this->cache->put($key, ['data' => 'test'], 3600);

        expect($this->cache->has($key))->toBeTrue();

        $result = $this->cache->forget($key);

        expect($result)->toBeTrue()
            ->and($this->cache->has($key))->toBeFalse();
    });

    it('returns true when forgetting non-existent key', function () {
        $result = $this->cache->forget('non-existent-key');

        expect($result)->toBeTrue();
    });
});

describe('TTL and expiration', function () {
    it('respects TTL and expires data', function () {
        $key = 'ttl-test';
        $value = ['status' => 'valid'];

        $this->cache->put($key, $value, 1);

        expect($this->cache->get($key))->toBe($value);

        sleep(2);

        expect($this->cache->get($key))->toBeNull();
    });

    it('removes expired file when getting expired data', function () {
        $key = 'expired-cleanup-test';

        $this->cache->put($key, ['test' => true], 1);
        sleep(2);

        $this->cache->get($key);

        expect($this->cache->has($key))->toBeFalse();
    });
});

describe('flush operation', function () {
    it('flushes all cached data', function () {
        $this->cache->put('key1', ['data' => '1'], 3600);
        $this->cache->put('key2', ['data' => '2'], 3600);
        $this->cache->put('key3', ['data' => '3'], 3600);

        expect($this->cache->has('key1'))->toBeTrue()
            ->and($this->cache->has('key2'))->toBeTrue()
            ->and($this->cache->has('key3'))->toBeTrue();

        $result = $this->cache->flush();

        expect($result)->toBeTrue()
            ->and($this->cache->has('key1'))->toBeFalse()
            ->and($this->cache->has('key2'))->toBeFalse()
            ->and($this->cache->has('key3'))->toBeFalse();
    });

    it('returns true when flushing empty cache', function () {
        $result = $this->cache->flush();

        expect($result)->toBeTrue();
    });
});

describe('data integrity', function () {
    it('handles complex nested data structures', function () {
        $key = 'complex-data';
        $value = [
            'status' => 'valid',
            'nested' => [
                'level1' => [
                    'level2' => ['a', 'b', 'c'],
                ],
            ],
            'numbers' => [1, 2, 3, 4, 5],
            'boolean' => true,
            'null_value' => null,
        ];

        $this->cache->put($key, $value, 3600);
        $retrieved = $this->cache->get($key);

        expect($retrieved)->toBe($value);
    });

    it('handles unicode data', function () {
        $key = 'unicode-test';
        $value = [
            'russian' => 'Привет мир',
            'emoji' => '🔐🔒',
            'chinese' => '你好世界',
        ];

        $this->cache->put($key, $value, 3600);
        $retrieved = $this->cache->get($key);

        expect($retrieved)->toBe($value);
    });

    it('handles large data', function () {
        $key = 'large-data';
        $value = [
            'large_string' => str_repeat('x', 50000),
            'large_array' => range(1, 1000),
        ];

        $this->cache->put($key, $value, 3600);
        $retrieved = $this->cache->get($key);

        expect($retrieved)->toBe($value);
    });
});

describe('key hashing', function () {
    it('handles various key formats', function () {
        $keys = [
            'simple-key',
            'key_with_underscores',
            'key.with.dots',
            'key/with/slashes',
            'key:with:colons',
            'KEY_WITH_CAPS',
            '123numeric456',
        ];

        foreach ($keys as $index => $key) {
            $value = ['index' => $index];
            $this->cache->put($key, $value, 3600);

            expect($this->cache->get($key))->toBe($value);
        }
    });

    it('distinguishes between different keys', function () {
        $this->cache->put('key1', ['value' => 'one'], 3600);
        $this->cache->put('key2', ['value' => 'two'], 3600);

        expect($this->cache->get('key1'))->toBe(['value' => 'one'])
            ->and($this->cache->get('key2'))->toBe(['value' => 'two']);
    });
});

describe('edge cases', function () {
    it('handles empty array values', function () {
        $key = 'empty-array';
        $this->cache->put($key, [], 3600);

        $retrieved = $this->cache->get($key);

        expect($retrieved)->toBe([]);
    });

    it('overwrites existing cache entries', function () {
        $key = 'overwrite-test';

        $this->cache->put($key, ['version' => 1], 3600);
        expect($this->cache->get($key))->toBe(['version' => 1]);

        $this->cache->put($key, ['version' => 2], 3600);
        expect($this->cache->get($key))->toBe(['version' => 2]);
    });

    it('creates cache directory if it does not exist', function () {
        $cachePath = $this->getTempPath('cache');
        if (is_dir($cachePath)) {
            $this->deleteDirectory($cachePath);
        }

        expect(is_dir($cachePath))->toBeFalse();

        $this->cache->put('directory-creation-test', ['test' => true], 3600);

        expect(is_dir($cachePath))->toBeTrue();
    });
});
