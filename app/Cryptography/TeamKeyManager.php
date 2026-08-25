<?php

namespace App\Cryptography;

use App\Contracts\SecretCipher;
use App\Exceptions\DecryptionFailed;
use App\Models\Team;
use App\Models\TeamKey;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

/**
 * Envelope encryption for a team's secrets.
 *
 * Every team owns a data encryption key (DEK). The DEK never leaves this
 * class in stored form: it is wrapped with the master key and only unwrapped
 * in memory. Variable values are encrypted with the DEK, not with the master
 * key, so a key rotation only has to rewrite one row per team instead of
 * re-encrypting every secret.
 *
 * The DEK is scoped to the team rather than the project on purpose: a
 * variable can be shared across projects, and a per-project key would force a
 * re-encryption on every share.
 */
class TeamKeyManager
{
    private const DEK_BYTES = 32;

    /**
     * Unwrapped data keys, cached per team for the lifetime of the request.
     *
     * @var array<int, string>
     */
    private array $cache = [];

    public function __construct(
        private readonly SecretCipher $cipher,
        private readonly MasterKeyProvider $masterKeys,
    ) {}

    /**
     * Get the team's data key, provisioning one if the team has none yet.
     */
    public function dataKeyFor(Team $team): string
    {
        return $this->cache[$team->id] ??= $this->unwrap(
            $team->currentKey() ?? $this->provision($team)
        );
    }

    /**
     * Encrypt a value with the team's data key.
     */
    public function encryptFor(Team $team, #[SensitiveParameter] string $plaintext): string
    {
        return $this->cipher->encrypt($plaintext, $this->dataKeyFor($team));
    }

    /**
     * Decrypt a value with the team's data key.
     */
    public function decryptFor(Team $team, string $payload): string
    {
        return $this->cipher->decrypt($payload, $this->dataKeyFor($team));
    }

    /**
     * Create and store a fresh wrapped data key for the team.
     */
    public function provision(Team $team): TeamKey
    {
        return DB::transaction(function () use ($team) {
            $version = (int) $team->keys()->max('version') + 1;

            return $team->keys()->create([
                'version' => $version,
                'wrapped_key' => $this->cipher->encrypt(
                    random_bytes(self::DEK_BYTES),
                    $this->masterKeys->current(),
                ),
                'algorithm' => AesGcmSecretCipher::VERSION,
            ]);
        });
    }

    /**
     * Unwrap a stored key, walking the current and retired master keys.
     *
     * Trying every master key is what makes a rotation gradual: keys wrapped
     * before the rotation still open, while new ones use the current key.
     */
    private function unwrap(TeamKey $key): string
    {
        foreach ($this->masterKeys->all() as $masterKey) {
            try {
                return $this->cipher->decrypt($key->wrapped_key, $masterKey);
            } catch (DecryptionFailed) {
                continue;
            }
        }

        throw DecryptionFailed::authenticationFailed();
    }
}
