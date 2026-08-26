<?php

namespace App\Data;

/**
 * One key as it appears across every environment of a project.
 *
 * Carries no value and no checksum. Equality between environments is folded
 * into a group number while the comparison is still server side, so a page
 * that shows "staging and production hold the same value" never has to ship
 * the fingerprint that proves it.
 */
final readonly class DriftEntry
{
    /**
     * @param  array<string, int|null>  $groups  environment slug => value group, null when the environment does not expose the key at all
     * @param  list<string>  $reusedIn  environments that share a value with a guarded environment
     */
    public function __construct(
        public string $key,
        public array $groups,
        public array $reusedIn = [],
    ) {}

    /**
     * Get the environments that do not expose this key.
     *
     * @return list<string>
     */
    public function missingIn(): array
    {
        return array_keys(array_filter(
            $this->groups,
            fn (?int $group) => $group === null,
        ));
    }

    /**
     * Determine whether every environment exposes this key.
     */
    public function isEverywhere(): bool
    {
        return $this->missingIn() === [];
    }

    /**
     * Determine whether the environments that do have this key disagree
     * about its value.
     */
    public function differs(): bool
    {
        $present = array_filter($this->groups, fn (?int $group) => $group !== null);

        return count(array_unique($present)) > 1;
    }
}
