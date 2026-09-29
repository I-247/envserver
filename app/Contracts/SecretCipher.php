<?php

namespace App\Contracts;

use App\Exceptions\DecryptionFailed;

interface SecretCipher
{
    /**
     * Encrypt a value with the given 256-bit key.
     *
     * The returned payload is self describing: it carries the scheme version
     * and everything needed to decrypt it apart from the key and the context.
     * The context is authenticated but not stored: decrypting with any other
     * context fails, which is what stops a payload from being moved to
     * another row.
     */
    public function encrypt(string $plaintext, string $key, string $context = ''): string;

    /**
     * Decrypt a payload produced by encrypt().
     *
     * @throws DecryptionFailed when the payload is malformed, was written by
     *                          an unsupported scheme, or fails authentication.
     */
    public function decrypt(string $payload, string $key, string $context = ''): string;

    /**
     * Determine whether a payload was written by a scheme that had no context.
     */
    public function isLegacy(string $payload): bool;
}
