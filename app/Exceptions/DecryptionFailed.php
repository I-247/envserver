<?php

namespace App\Exceptions;

use RuntimeException;

class DecryptionFailed extends RuntimeException
{
    /**
     * The payload could not be parsed into its parts.
     */
    public static function malformedPayload(): self
    {
        return new self('The encrypted payload is malformed.');
    }

    /**
     * The payload was written by a scheme this build does not know.
     */
    public static function unsupportedScheme(string $version): self
    {
        return new self("Unsupported encryption scheme [{$version}].");
    }

    /**
     * Authentication failed: wrong key, or the payload was tampered with.
     */
    public static function authenticationFailed(): self
    {
        return new self('The encrypted payload could not be authenticated.');
    }
}
