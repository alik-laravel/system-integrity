<?php

declare(strict_types=1);

namespace Alik\SystemIntegrity\Support;

/**
 * Cryptographic helper for configuration data.
 */
final class CryptoHelper
{
    private const CIPHER = 'aes-256-gcm';

    private const TAG_LENGTH = 16;

    /**
     * Decrypt configuration data.
     *
     * @return array<string, mixed>|null
     */
    public function decryptConfigurationData(string $encryptedData, string $deviceHash): ?array
    {
        try {
            $combined = base64_decode($encryptedData, true);
            if ($combined === false) {
                return null;
            }

            $ivLength = 12;
            if (strlen($combined) < $ivLength + self::TAG_LENGTH) {
                return null;
            }

            $iv = substr($combined, 0, $ivLength);
            $tag = substr($combined, -self::TAG_LENGTH);
            $ciphertext = substr($combined, $ivLength, -self::TAG_LENGTH);

            $key = $this->deriveKey($deviceHash);

            $decrypted = openssl_decrypt(
                $ciphertext,
                self::CIPHER,
                $key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );

            if ($decrypted === false) {
                return null;
            }

            $data = json_decode($decrypted, true);
            if (! is_array($data)) {
                return null;
            }

            return $data;
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Encrypt configuration data.
     *
     * @param  array<string, mixed>  $data
     */
    public function encryptConfigurationData(array $data, string $deviceHash): string
    {
        $json = json_encode($data);
        $key = $this->deriveKey($deviceHash);
        $iv = random_bytes(12);

        $tag = '';
        $ciphertext = openssl_encrypt(
            (string) $json,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LENGTH
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('Encryption failed');
        }

        $combined = $iv . $ciphertext . $tag;

        return base64_encode($combined);
    }

    /**
     * Generate HMAC signature.
     */
    public function generateSignature(string $data, string $secret): string
    {
        return hash_hmac('sha256', $data, $secret);
    }

    /**
     * Verify HMAC signature.
     */
    public function verifySignature(string $data, string $signature, string $secret): bool
    {
        $expected = $this->generateSignature($data, $secret);

        return hash_equals($expected, $signature);
    }

    /**
     * Derive encryption key from device hash.
     */
    private function deriveKey(string $deviceHash): string
    {
        $encryptionKey = config('integrity.encryption_key', config('app.key'));

        return hash_pbkdf2(
            'sha256',
            (string) $encryptionKey,
            $deviceHash,
            100000,
            32,
            true
        );
    }
}
