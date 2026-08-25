<?php

namespace App\Exceptions;

use RuntimeException;

class MasterKeyMissing extends RuntimeException
{
    /**
     * No master key is configured at all.
     */
    public static function notConfigured(): self
    {
        return new self('No KLUIS_MASTER_KEY is configured. Generate one with "php artisan kluis:master-key".');
    }

    /**
     * A master key is configured, but it cannot be used.
     */
    public static function unusable(): self
    {
        return new self('The configured KLUIS_MASTER_KEY is not a 256-bit key.');
    }
}
