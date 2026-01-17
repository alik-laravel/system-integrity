<?php

declare(strict_types=1);

namespace Alik\SystemIntegrity\Services;

use Illuminate\Support\Facades\Http;

/**
 * Service for remote configuration management.
 */
final class RemoteConfigService
{
    public function __construct(
        private readonly SystemProfiler $profiler
    ) {}

    /**
     * Activate and retrieve configuration from remote service.
     *
     * @return array{success: bool, data?: string, error?: string}
     */
    public function activateConfiguration(string $apiKey): array
    {
        $apiUrl = config('integrity.validation_api_url');
        if (empty($apiUrl)) {
            return [
                'success' => false,
                'error' => 'Validation API URL not configured',
            ];
        }

        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'X-API-Key' => $apiKey,
                ])
                ->post($apiUrl . '/v1/activate', [
                    'device_hash' => $this->profiler->getSystemSignature(),
                    'metadata' => $this->profiler->collectMetadata(),
                ]);

            if (! $response->successful()) {
                $error = $response->json('error') ?? 'Activation failed';

                return [
                    'success' => false,
                    'error' => $error,
                ];
            }

            return [
                'success' => true,
                'data' => $response->body(),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'Connection error: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Validate configuration with remote service.
     *
     * @return array{valid: bool, reason?: string, expires_at?: int}
     */
    public function validateConfiguration(string $licenseId): array
    {
        $apiUrl = config('integrity.validation_api_url');
        if (empty($apiUrl)) {
            return [
                'valid' => false,
                'reason' => 'Validation API URL not configured',
            ];
        }

        try {
            $response = Http::timeout(10)
                ->post($apiUrl . '/v1/validate', [
                    'license_id' => $licenseId,
                    'device_hash' => $this->profiler->getSystemSignature(),
                    'timestamp' => time() * 1000,
                ]);

            if (! $response->successful()) {
                return [
                    'valid' => false,
                    'reason' => 'Request failed',
                ];
            }

            return $response->json();
        } catch (\Exception $e) {
            return [
                'valid' => false,
                'reason' => 'Connection error',
            ];
        }
    }
}
