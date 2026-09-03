<?php

namespace App\Data;

use App\Enums\ChangeType;

/**
 * One difference between two snapshots of an environment.
 *
 * The values are optional: a diff is often rendered for people who may see
 * that something changed without being allowed to see what it changed to.
 */
final readonly class VariableChange
{
    public function __construct(
        public string $key,
        public ChangeType $type,
        public ?string $before = null,
        public ?string $after = null,
    ) {}
}
