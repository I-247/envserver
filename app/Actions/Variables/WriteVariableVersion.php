<?php

namespace App\Actions\Variables;

use App\Cryptography\TeamKeyManager;
use App\Models\Team;
use App\Models\User;
use App\Models\Variable;
use App\Models\VariableVersion;
use SensitiveParameter;

/**
 * Appends a new immutable version to a variable.
 *
 * Shared by CreateVariable and UpdateVariableValue so there is exactly one
 * place that decides how a value is encrypted and fingerprinted.
 */
class WriteVariableVersion
{
    public function __construct(private readonly TeamKeyManager $keys) {}

    /**
     * Write the next version of the variable.
     */
    public function handle(
        Variable $variable,
        #[SensitiveParameter] string $value,
        ?User $author = null,
        ?string $note = null,
    ): VariableVersion {
        $team = $variable->team;

        return $variable->versions()->create([
            'version' => (int) $variable->versions()->max('version') + 1,
            'ciphertext' => $this->keys->encryptFor($team, $value),
            'checksum' => $this->checksum($team, $value),
            'team_key_version' => $team->currentKey()->version,
            'author_id' => $author?->id,
            'note' => $note,
        ]);
    }

    /**
     * Fingerprint a value so equality can be checked without decrypting.
     *
     * An HMAC rather than a bare hash: a plain SHA-256 of a low entropy
     * secret is crackable offline from a database dump, while an HMAC keyed
     * with the team's data key is worthless without that key.
     */
    public function checksum(Team $team, #[SensitiveParameter] string $value): string
    {
        return hash_hmac('sha256', $value, $this->keys->dataKeyFor($team));
    }
}
