<?php

declare(strict_types=1);

use Alik\SystemIntegrity\Support\CryptoHelper;

beforeEach(function () {
    $this->crypto = new CryptoHelper();
    $this->deviceHash = hash('sha256', 'test-device-identifier');
});

describe('encryption and decryption', function () {
    it('encrypts and decrypts data correctly', function () {
        $originalData = [
            'id' => 'test-license-id',
            'device_hash' => $this->deviceHash,
            'issued_at' => time() * 1000,
            'expires_at' => (time() + 86400) * 1000,
            'signature' => 'test-signature',
        ];

        $encrypted = $this->crypto->encryptConfigurationData($originalData, $this->deviceHash);

        expect($encrypted)->toBeString()
            ->and($encrypted)->not->toBe(json_encode($originalData));

        $decrypted = $this->crypto->decryptConfigurationData($encrypted, $this->deviceHash);

        expect($decrypted)->toBe($originalData);
    });

    it('returns null when decrypting with wrong device hash', function () {
        $originalData = [
            'id' => 'test-license-id',
            'device_hash' => $this->deviceHash,
        ];

        $encrypted = $this->crypto->encryptConfigurationData($originalData, $this->deviceHash);

        $wrongDeviceHash = hash('sha256', 'wrong-device');
        $decrypted = $this->crypto->decryptConfigurationData($encrypted, $wrongDeviceHash);

        expect($decrypted)->toBeNull();
    });

    it('returns null for invalid base64 input', function () {
        $result = $this->crypto->decryptConfigurationData('not-valid-base64!!!', $this->deviceHash);

        expect($result)->toBeNull();
    });

    it('returns null for too short encrypted data', function () {
        $shortData = base64_encode('short');
        $result = $this->crypto->decryptConfigurationData($shortData, $this->deviceHash);

        expect($result)->toBeNull();
    });

    it('returns null for corrupted encrypted data', function () {
        $originalData = ['id' => 'test'];
        $encrypted = $this->crypto->encryptConfigurationData($originalData, $this->deviceHash);

        $corrupted = base64_encode(substr(base64_decode($encrypted), 0, -5) . 'xxxxx');
        $result = $this->crypto->decryptConfigurationData($corrupted, $this->deviceHash);

        expect($result)->toBeNull();
    });

    it('returns null when decrypted data is not valid JSON array', function () {
        $result = $this->crypto->decryptConfigurationData('', $this->deviceHash);

        expect($result)->toBeNull();
    });

    it('produces different ciphertext for same data (due to random IV)', function () {
        $data = ['id' => 'test'];

        $encrypted1 = $this->crypto->encryptConfigurationData($data, $this->deviceHash);
        $encrypted2 = $this->crypto->encryptConfigurationData($data, $this->deviceHash);

        expect($encrypted1)->not->toBe($encrypted2);

        $decrypted1 = $this->crypto->decryptConfigurationData($encrypted1, $this->deviceHash);
        $decrypted2 = $this->crypto->decryptConfigurationData($encrypted2, $this->deviceHash);

        expect($decrypted1)->toBe($data)
            ->and($decrypted2)->toBe($data);
    });
});

describe('HMAC signatures', function () {
    it('generates consistent signatures for same data and secret', function () {
        $data = 'test-data-to-sign';
        $secret = 'test-secret-key';

        $signature1 = $this->crypto->generateSignature($data, $secret);
        $signature2 = $this->crypto->generateSignature($data, $secret);

        expect($signature1)->toBe($signature2)
            ->and($signature1)->toHaveLength(64);
    });

    it('generates different signatures for different data', function () {
        $secret = 'test-secret-key';

        $signature1 = $this->crypto->generateSignature('data1', $secret);
        $signature2 = $this->crypto->generateSignature('data2', $secret);

        expect($signature1)->not->toBe($signature2);
    });

    it('generates different signatures for different secrets', function () {
        $data = 'test-data';

        $signature1 = $this->crypto->generateSignature($data, 'secret1');
        $signature2 = $this->crypto->generateSignature($data, 'secret2');

        expect($signature1)->not->toBe($signature2);
    });

    it('verifies valid signatures correctly', function () {
        $data = 'test-data-to-sign';
        $secret = 'test-secret-key';

        $signature = $this->crypto->generateSignature($data, $secret);
        $isValid = $this->crypto->verifySignature($data, $signature, $secret);

        expect($isValid)->toBeTrue();
    });

    it('rejects invalid signatures', function () {
        $data = 'test-data-to-sign';
        $secret = 'test-secret-key';

        $isValid = $this->crypto->verifySignature($data, 'invalid-signature', $secret);

        expect($isValid)->toBeFalse();
    });

    it('rejects signatures with wrong secret', function () {
        $data = 'test-data-to-sign';

        $signature = $this->crypto->generateSignature($data, 'correct-secret');
        $isValid = $this->crypto->verifySignature($data, $signature, 'wrong-secret');

        expect($isValid)->toBeFalse();
    });

    it('rejects signatures with modified data', function () {
        $secret = 'test-secret-key';

        $signature = $this->crypto->generateSignature('original-data', $secret);
        $isValid = $this->crypto->verifySignature('modified-data', $signature, $secret);

        expect($isValid)->toBeFalse();
    });
});

describe('edge cases', function () {
    it('handles empty array encryption', function () {
        $encrypted = $this->crypto->encryptConfigurationData([], $this->deviceHash);
        $decrypted = $this->crypto->decryptConfigurationData($encrypted, $this->deviceHash);

        expect($decrypted)->toBe([]);
    });

    it('handles nested arrays', function () {
        $data = [
            'id' => 'test',
            'nested' => [
                'level1' => [
                    'level2' => 'value',
                ],
            ],
            'array' => [1, 2, 3],
        ];

        $encrypted = $this->crypto->encryptConfigurationData($data, $this->deviceHash);
        $decrypted = $this->crypto->decryptConfigurationData($encrypted, $this->deviceHash);

        expect($decrypted)->toBe($data);
    });

    it('handles unicode data', function () {
        $data = [
            'name' => 'Тест Unicode',
            'emoji' => '🔐',
        ];

        $encrypted = $this->crypto->encryptConfigurationData($data, $this->deviceHash);
        $decrypted = $this->crypto->decryptConfigurationData($encrypted, $this->deviceHash);

        expect($decrypted)->toBe($data);
    });

    it('handles large data', function () {
        $data = [
            'large_string' => str_repeat('a', 10000),
            'large_array' => range(1, 1000),
        ];

        $encrypted = $this->crypto->encryptConfigurationData($data, $this->deviceHash);
        $decrypted = $this->crypto->decryptConfigurationData($encrypted, $this->deviceHash);

        expect($decrypted)->toBe($data);
    });

    it('handles special characters in device hash', function () {
        $specialHash = hash('sha256', 'device-with-special-chars!@#$%^&*()');
        $data = ['id' => 'test'];

        $encrypted = $this->crypto->encryptConfigurationData($data, $specialHash);
        $decrypted = $this->crypto->decryptConfigurationData($encrypted, $specialHash);

        expect($decrypted)->toBe($data);
    });
});
