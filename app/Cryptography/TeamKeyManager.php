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
     * Unwrapped data keys, cached per team and key version for the request.
     *
     * @var array<string, string>
     */
    private array $cache = [];

    public function __construct(
        private readonly SecretCipher $cipher,
        private readonly MasterKeyProvider $masterKeys,
    ) {}

    /**
     * The context a variable version's ciphertext is bound to.
     */
    public static function valueContext(int $teamId, int $variableId, int $version): string
    {
        return "envserver:value:team={$teamId}:variable={$variableId}:version={$version}";
    }

    /**
     * The context a wrapped data key is bound to.
     */
    public static function teamKeyContext(int $teamId, int $version): string
    {
        return "envserver:team-key:team={$teamId}:version={$version}";
    }

    /**
     * Get one of the team's data keys, provisioning one if the team has none yet.
     *
     * Without a version this is the key new values are written with. With
     * one, it is the key a stored value was written with: reading through
     * the current key would break every older value the day a team got a
     * second key.
     */
    public function dataKeyFor(Team $team, ?int $version = null): string
    {
        $key = $version === null
            ? ($team->currentKey() ?? $this->provision($team))
            : $team->keys()->where('version', $version)->first();

        if ($key === null) {
            throw DecryptionFailed::authenticationFailed();
        }

        return $this->cache["{$team->id}:{$key->version}"] ??= $this->unwrap($key);
    }

    /**
     * Encrypt a value with the team's current data key.
     */
    public function encryptFor(Team $team, #[SensitiveParameter] string $plaintext, string $context = ''): string
    {
        return $this->cipher->encrypt($plaintext, $this->dataKeyFor($team), $context);
    }

    /**
     * Decrypt a value with the team data key it was written with.
     */
    public function decryptFor(Team $team, string $payload, string $context = '', ?int $keyVersion = null): string
    {
        return $this->cipher->decrypt($payload, $this->dataKeyFor($team, $keyVersion), $context);
    }

    /**
     * Get the team's current data key, creating the first one if needed.
     *
     * The team row is locked while deciding: two requests racing on a brand
     * new team would otherwise both find no key and each create one, and
     * values written under the losing key would stop decrypting.
     */
    public function provision(Team $team): TeamKey
    {
        return DB::transaction(function () use ($team) {
            Team::query()->whereKey($team->id)->lockForUpdate()->first();

            if ($existing = $team->currentKey()) {
                return $existing;
            }

            $version = (int) $team->keys()->max('version') + 1;

            return $team->keys()->create([
                'version' => $version,
                'wrapped_key' => $this->cipher->encrypt(
                    random_bytes(self::DEK_BYTES),
                    $this->masterKeys->current(),
                    self::teamKeyContext($team->id, $version),
                ),
                'algorithm' => AesGcmSecretCipher::VERSION,
            ]);
        });
    }

    /**
     * Re-wrap every data key of the team with the current master key.
     *
     * Cheap by design: the data keys themselves are unchanged, so not a
     * single stored secret has to be re-encrypted. This is what a master key
     * rotation actually costs, and it is why the DEK exists at all. Retired
     * keys are included, otherwise removing the old master key afterwards
     * orphans every value they still protect.
     */
    public function rewrap(Team $team): bool
    {
        DB::transaction(function () use ($team) {
            Team::query()->whereKey($team->id)->lockForUpdate()->first();

            foreach ($team->keys()->get() as $key) {
                $dataKey = $this->unwrap($key);
                $context = self::teamKeyContext($team->id, $key->version);
                $wrapped = $this->cipher->encrypt($dataKey, $this->masterKeys->current(), $context);

                // Proven to open before the old wrapping is overwritten: a
                // bad write here would lose every secret under this key.
                if (! hash_equals($dataKey, $this->cipher->decrypt($wrapped, $this->masterKeys->current(), $context))) {
                    throw DecryptionFailed::authenticationFailed();
                }

                $key->forceFill([
                    'wrapped_key' => $wrapped,
                    'algorithm' => AesGcmSecretCipher::VERSION,
                ])->save();
            }
        });

        foreach (array_keys($this->cache) as $cached) {
            if (str_starts_with($cached, "{$team->id}:")) {
                unset($this->cache[$cached]);
            }
        }

        return true;
    }

    /**
     * Unwrap a stored key, walking the current and retired master keys.
     *
     * Trying every master key is what makes a rotation gradual: keys wrapped
     * before the rotation still open, while new ones use the current key.
     */
    private function unwrap(TeamKey $key): string
    {
        $context = self::teamKeyContext($key->team_id, $key->version);

        foreach ($this->masterKeys->all() as $masterKey) {
            try {
                return $this->cipher->decrypt($key->wrapped_key, $masterKey, $context);
            } catch (DecryptionFailed) {
                continue;
            }
        }

        throw DecryptionFailed::authenticationFailed();
    }
}
