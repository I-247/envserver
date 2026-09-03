<?php

namespace App\Support;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Str;
use Stringable;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * A list of IP addresses and CIDR ranges that access may be limited to.
 *
 * An empty list means "no restriction". That is deliberate: every allow list
 * in this application is opt in, and an empty list must never be read as
 * "nobody may in" — that would lock a team out the moment they cleared the
 * field.
 *
 * @implements Arrayable<int, string>
 */
final readonly class IpAllowList implements Arrayable, Stringable
{
    /**
     * @param  list<string>  $entries
     */
    private function __construct(private array $entries) {}

    /**
     * Build a list from stored or configured entries.
     *
     * @param  iterable<mixed>|null  $entries
     */
    public static function make(?iterable $entries): self
    {
        $normalised = [];

        foreach ($entries ?? [] as $entry) {
            if (! is_scalar($entry)) {
                continue;
            }

            $entry = trim((string) $entry);

            if ($entry !== '' && ! in_array($entry, $normalised, strict: true)) {
                $normalised[] = $entry;
            }
        }

        return new self($normalised);
    }

    /**
     * Build a list from free form text: one entry per line, comma, or space.
     *
     * The team facing field is a textarea, and an operator pasting a range
     * list should not have to care which separator they used.
     */
    public static function parse(?string $value): self
    {
        return self::make(preg_split('/[\s,;]+/', (string) $value) ?: []);
    }

    /**
     * Determine whether an entry is a usable IP address or CIDR range.
     */
    public static function isValidEntry(string $entry): bool
    {
        if (filter_var($entry, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        if (! Str::contains($entry, '/')) {
            return false;
        }

        [$address, $prefix] = explode('/', $entry, 2);

        if (filter_var($address, FILTER_VALIDATE_IP) === false || ! ctype_digit($prefix)) {
            return false;
        }

        $maximum = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? 128 : 32;

        return (int) $prefix >= 0 && (int) $prefix <= $maximum;
    }

    /**
     * Determine whether the list restricts anything at all.
     */
    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    /**
     * Determine whether the list restricts access.
     */
    public function isNotEmpty(): bool
    {
        return ! $this->isEmpty();
    }

    /**
     * Determine whether the given address may pass.
     *
     * A request without a resolvable IP is only allowed through while the
     * list is empty: an unknown address cannot be shown to be on the list.
     */
    public function allows(?string $ip): bool
    {
        if ($this->isEmpty()) {
            return true;
        }

        return $ip !== null && IpUtils::checkIp($ip, $this->entries);
    }

    /**
     * Get the entries as a plain array, or null when nothing is restricted.
     *
     * Storing null rather than an empty array keeps "off" a single value in
     * the database instead of two that mean the same thing.
     *
     * @return list<string>|null
     */
    public function toStorage(): ?array
    {
        return $this->isEmpty() ? null : $this->entries;
    }

    /**
     * @return list<string>
     */
    public function toArray(): array
    {
        return $this->entries;
    }

    /**
     * Render the list the way the team facing textarea shows it.
     */
    public function __toString(): string
    {
        return implode("\n", $this->entries);
    }
}
