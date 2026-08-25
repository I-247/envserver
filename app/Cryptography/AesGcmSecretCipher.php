<?php

namespace App\Cryptography;

use App\Contracts\SecretCipher;
use App\Exceptions\DecryptionFailed;
use InvalidArgumentException;
use SensitiveParameter;

/**
 * AES-256-GCM with a random nonce per encryption.
 *
 * Payloads look like "v1.{nonce}.{tag}.{ciphertext}", each part base64 encoded.
 * The version prefix is what makes the scheme replaceable: a future v2 can be
 * introduced while v1 payloads still decrypt, so a migration never has to be a
 * big-bang re-encryption of the whole database.
 */
class AesGcmSecretCipher implements SecretCipher
{
    public const VERSION = 'v1';

    private const CIPHER = 'aes-256-gcm';

    private const KEY_BYTES = 32;

    private const NONCE_BYTES = 12;

    private const TAG_BYTES = 16;

    public function encrypt(#[SensitiveParameter] string $plaintext, #[SensitiveParameter] string $key): string
    {
        $this->assertUsableKey($key);

        $nonce = random_bytes(self::NONCE_BYTES);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '',
            self::TAG_BYTES,
        );

        if ($ciphertext === false) {
            throw new InvalidArgumentException('Unable to encrypt the given value.');
        }

        return implode('.', [
            self::VERSION,
            base64_encode($nonce),
            base64_encode($tag),
            base64_encode($ciphertext),
        ]);
    }

    public function decrypt(string $payload, #[SensitiveParameter] string $key): string
    {
        $this->assertUsableKey($key);

        $parts = explode('.', $payload);

        if (count($parts) !== 4) {
            throw DecryptionFailed::malformedPayload();
        }

        [$version, $nonce, $tag, $ciphertext] = $parts;

        if ($version !== self::VERSION) {
            throw DecryptionFailed::unsupportedScheme($version);
        }

        $nonce = $this->decodeStrict($nonce);
        $tag = $this->decodeStrict($tag);
        $ciphertext = $this->decodeStrict($ciphertext);

        if (strlen($nonce) !== self::NONCE_BYTES || strlen($tag) !== self::TAG_BYTES) {
            throw DecryptionFailed::malformedPayload();
        }

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
        );

        if ($plaintext === false) {
            throw DecryptionFailed::authenticationFailed();
        }

        return $plaintext;
    }

    /**
     * Decode base64, rejecting anything that is not valid base64.
     */
    private function decodeStrict(string $value): string
    {
        $decoded = base64_decode($value, strict: true);

        if ($decoded === false) {
            throw DecryptionFailed::malformedPayload();
        }

        return $decoded;
    }

    /**
     * Guard against a key of the wrong size silently weakening the cipher.
     */
    private function assertUsableKey(#[SensitiveParameter] string $key): void
    {
        if (strlen($key) !== self::KEY_BYTES) {
            throw new InvalidArgumentException('A 256-bit key is required.');
        }
    }
}
