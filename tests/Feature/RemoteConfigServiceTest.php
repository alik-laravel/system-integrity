<?php

declare(strict_types=1);

use Alik\SystemIntegrity\Services\RemoteConfigService;
use Alik\SystemIntegrity\Services\SystemProfiler;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->profiler = new SystemProfiler();
    $this->service = new RemoteConfigService($this->profiler);
});

describe('activateConfiguration', function () {
    it('successfully activates configuration', function () {
        $encryptedData = base64_encode('encrypted-license-data');

        Http::fake([
            '*/v1/activate' => Http::response($encryptedData, 200),
        ]);

        $result = $this->service->activateConfiguration('pk_test_api_key');

        expect($result)->toBeArray()
            ->and($result['success'])->toBeTrue()
            ->and($result['data'])->toBe($encryptedData);
    });

    it('sends correct headers and body', function () {
        Http::fake([
            '*/v1/activate' => Http::response('encrypted-data', 200),
        ]);

        $this->service->activateConfiguration('pk_test_api_key');

        Http::assertSent(function ($request) {
            return $request->hasHeader('X-API-Key', 'pk_test_api_key')
                && $request['device_hash'] === $this->profiler->getSystemSignature()
                && isset($request['metadata'])
                && is_array($request['metadata']);
        });
    });

    it('returns error when API URL is not configured', function () {
        config(['integrity.validation_api_url' => null]);

        $result = $this->service->activateConfiguration('pk_test_key');

        expect($result['success'])->toBeFalse()
            ->and($result['error'])->toBe('Validation API URL not configured');
    });

    it('returns error on HTTP failure', function () {
        Http::fake([
            '*/v1/activate' => Http::response(['error' => 'Invalid API key'], 401),
        ]);

        $result = $this->service->activateConfiguration('pk_invalid_key');

        expect($result['success'])->toBeFalse()
            ->and($result['error'])->toBe('Invalid API key');
    });

    it('returns error on server error', function () {
        Http::fake([
            '*/v1/activate' => Http::response(['error' => 'Internal server error'], 500),
        ]);

        $result = $this->service->activateConfiguration('pk_test_key');

        expect($result['success'])->toBeFalse()
            ->and($result['error'])->toBe('Internal server error');
    });

    it('returns error on connection failure', function () {
        Http::fake([
            '*/v1/activate' => fn () => throw new \Exception('Connection refused'),
        ]);

        $result = $this->service->activateConfiguration('pk_test_key');

        expect($result['success'])->toBeFalse()
            ->and($result['error'])->toContain('Connection error');
    });

    it('returns generic error when no error message in response', function () {
        Http::fake([
            '*/v1/activate' => Http::response(null, 400),
        ]);

        $result = $this->service->activateConfiguration('pk_test_key');

        expect($result['success'])->toBeFalse()
            ->and($result['error'])->toBe('Activation failed');
    });

    it('includes system metadata in request', function () {
        Http::fake([
            '*/v1/activate' => Http::response('data', 200),
        ]);

        $this->service->activateConfiguration('pk_test_key');

        Http::assertSent(function ($request) {
            $metadata = $request['metadata'];

            return isset($metadata['hostname'])
                && isset($metadata['os'])
                && isset($metadata['php_version'])
                && isset($metadata['collected_at']);
        });
    });
});

describe('validateConfiguration', function () {
    it('successfully validates configuration', function () {
        Http::fake([
            '*/v1/validate' => Http::response([
                'valid' => true,
                'expires_at' => time() * 1000 + 86400000,
            ], 200),
        ]);

        $result = $this->service->validateConfiguration('test-license-id');

        expect($result['valid'])->toBeTrue()
            ->and($result)->toHaveKey('expires_at');
    });

    it('sends correct request body', function () {
        Http::fake([
            '*/v1/validate' => Http::response(['valid' => true], 200),
        ]);

        $licenseId = 'test-license-id-12345';
        $this->service->validateConfiguration($licenseId);

        Http::assertSent(function ($request) use ($licenseId) {
            return $request['license_id'] === $licenseId
                && $request['device_hash'] === $this->profiler->getSystemSignature()
                && isset($request['timestamp'])
                && $request['timestamp'] > 0;
        });
    });

    it('returns error when API URL is not configured', function () {
        config(['integrity.validation_api_url' => null]);

        $result = $this->service->validateConfiguration('test-license-id');

        expect($result['valid'])->toBeFalse()
            ->and($result['reason'])->toBe('Validation API URL not configured');
    });

    it('returns invalid on HTTP failure', function () {
        Http::fake([
            '*/v1/validate' => Http::response(['valid' => false, 'reason' => 'License expired'], 200),
        ]);

        $result = $this->service->validateConfiguration('expired-license-id');

        expect($result['valid'])->toBeFalse()
            ->and($result['reason'])->toBe('License expired');
    });

    it('returns invalid on server error', function () {
        Http::fake([
            '*/v1/validate' => Http::response(null, 500),
        ]);

        $result = $this->service->validateConfiguration('test-license-id');

        expect($result['valid'])->toBeFalse()
            ->and($result['reason'])->toBe('Request failed');
    });

    it('returns invalid on connection failure', function () {
        Http::fake([
            '*/v1/validate' => fn () => throw new \Exception('Network error'),
        ]);

        $result = $this->service->validateConfiguration('test-license-id');

        expect($result['valid'])->toBeFalse()
            ->and($result['reason'])->toBe('Connection error');
    });
});

describe('URL construction', function () {
    it('constructs correct activation URL', function () {
        Http::fake([
            '*/v1/activate' => Http::response('data', 200),
        ]);

        $this->service->activateConfiguration('pk_test');

        Http::assertSent(function ($request) {
            return str_ends_with($request->url(), '/v1/activate');
        });
    });

    it('constructs correct validation URL', function () {
        Http::fake([
            '*/v1/validate' => Http::response(['valid' => true], 200),
        ]);

        $this->service->validateConfiguration('test-id');

        Http::assertSent(function ($request) {
            return str_ends_with($request->url(), '/v1/validate');
        });
    });
});
