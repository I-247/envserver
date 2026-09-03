<?php

namespace App\Data;

use App\Models\Variable;
use App\Models\VariableVersion;

/**
 * One entry as it will appear in an environment's .env file.
 *
 * Carries the key it is exposed under (which may be an alias) together with
 * the exact version that produced it, so a release can pin it.
 */
final readonly class ResolvedVariable
{
    public function __construct(
        public string $key,
        public Variable $variable,
        public VariableVersion $version,
        public bool $shared,
    ) {}

    /**
     * Decrypt the value for this entry.
     */
    public function value(): string
    {
        return $this->version->reveal();
    }
}
