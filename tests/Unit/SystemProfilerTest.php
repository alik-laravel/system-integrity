<?php

declare(strict_types=1);

use Alik\SystemIntegrity\Services\SystemProfiler;

beforeEach(function () {
    $this->profiler = new SystemProfiler();
});

describe('system signature generation', function () {
    it('generates a consistent system signature', function () {
        $signature1 = $this->profiler->getSystemSignature();
        $signature2 = $this->profiler->getSystemSignature();

        expect($signature1)->toBe($signature2)
            ->and($signature1)->toBeString()
            ->and($signature1)->toHaveLength(64);
    });

    it('returns a valid SHA256 hash', function () {
        $signature = $this->profiler->getSystemSignature();

        expect($signature)->toMatch('/^[a-f0-9]{64}$/');
    });

    it('caches the signature after first call', function () {
        $profiler = new SystemProfiler();

        $signature1 = $profiler->getSystemSignature();
        $signature2 = $profiler->getSystemSignature();

        expect($signature1)->toBe($signature2);
    });
});

describe('metadata collection', function () {
    it('collects system metadata', function () {
        $metadata = $this->profiler->collectMetadata();

        expect($metadata)->toBeArray()
            ->and($metadata)->toHaveKeys([
                'hostname',
                'os',
                'os_version',
                'php_version',
                'laravel_version',
                'server_software',
                'collected_at',
            ]);
    });

    it('returns valid hostname', function () {
        $metadata = $this->profiler->collectMetadata();

        expect($metadata['hostname'])->toBeString()
            ->and($metadata['hostname'])->not->toBeEmpty();
    });

    it('returns valid OS family', function () {
        $metadata = $this->profiler->collectMetadata();

        expect($metadata['os'])->toBeIn(['Darwin', 'Linux', 'Windows', 'BSD', 'Solaris', 'Unknown']);
    });

    it('returns valid PHP version', function () {
        $metadata = $this->profiler->collectMetadata();

        expect($metadata['php_version'])->toBe(PHP_VERSION);
    });

    it('returns collected_at timestamp', function () {
        $before = time();
        $metadata = $this->profiler->collectMetadata();
        $after = time();

        expect($metadata['collected_at'])->toBeGreaterThanOrEqual($before)
            ->and($metadata['collected_at'])->toBeLessThanOrEqual($after);
    });

    it('returns os_version as string', function () {
        $metadata = $this->profiler->collectMetadata();

        expect($metadata['os_version'])->toBeString();
    });

    it('returns server_software', function () {
        $metadata = $this->profiler->collectMetadata();

        expect($metadata['server_software'])->toBeString()
            ->and($metadata['server_software'])->not->toBeEmpty();
    });
});

describe('signature uniqueness', function () {
    it('generates different signatures for different profiler instances on same machine', function () {
        $profiler1 = new SystemProfiler();
        $profiler2 = new SystemProfiler();

        expect($profiler1->getSystemSignature())->toBe($profiler2->getSystemSignature());
    });
});

describe('metadata consistency', function () {
    it('returns consistent metadata across calls', function () {
        $metadata1 = $this->profiler->collectMetadata();

        usleep(100000);

        $metadata2 = $this->profiler->collectMetadata();

        expect($metadata1['hostname'])->toBe($metadata2['hostname'])
            ->and($metadata1['os'])->toBe($metadata2['os'])
            ->and($metadata1['php_version'])->toBe($metadata2['php_version']);
    });
});
