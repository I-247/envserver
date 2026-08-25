<?php

namespace App\Cryptography;

use App\Exceptions\MasterKeyMissing;

/**
 * Resolves the master key that wraps every team's data encryption key.
 *
 * Deliberately separate from APP_KEY: rotating APP_KEY only costs everyone
 * their session, while losing the key that wraps the data keys would make
 * every stored secret unreadable.
 */
class MasterKeyProvider
{
    private const KEY_BYTES = 32;

    /**
     * The key new payloads are wrapped with.
     */
    public function current(): string
    {
        $key = $this->parse(config('kluis.master_key'));

        if ($key === null) {
            throw config('kluis.master_key')
                ? MasterKeyMissing::unusable()
                : MasterKeyMissing::notConfigured();
        }

        return $key;
    }

    /**
     * The current key followed by any retired keys.
     *
     * Unwrapping walks this list so a rotation can be rolled out without
     * downtime. Unusable entries are skipped rather than thrown on: a typo in
     * a retired key should not take the application down.
     *
     * @return list<string>
     */
    public function all(): array
    {
        $previous = array_filter(array_map(
            fn (mixed $key) => $this->parse($key),
            config('kluis.previous_master_keys', []),
        ));

        return [$this->current(), ...array_values($previous)];
    }

    /**
     * Decode a configured key into raw bytes, or null when unusable.
     */
    private function parse(mixed $key): ?string
    {
        if (! is_string($key) || $key === '') {
            return null;
        }

        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(mb_substr($key, 7), strict: true);
        }

        if ($key === false || strlen($key) !== self::KEY_BYTES) {
            return null;
        }

        return $key;
    }
}
