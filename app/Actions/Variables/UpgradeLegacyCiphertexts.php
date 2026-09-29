<?php

namespace App\Actions\Variables;

use App\Contracts\SecretCipher;
use App\Cryptography\AesGcmSecretCipher;
use App\Cryptography\TeamKeyManager;
use App\Models\VariableVersion;
use Illuminate\Support\Facades\DB;

/**
 * Re-encrypts every value still stored in the scheme without a context.
 *
 * A v1 payload decrypts in any row of the same team, so somebody with write
 * access to the database could move one secret into another variable. Once
 * every row is v2 no such payload is left to move.
 *
 * This is the one place a stored version is rewritten. The value does not
 * change, only how it is stored, so the append-only promise of
 * VariableVersion still holds for everything a user can see.
 */
class UpgradeLegacyCiphertexts
{
    public function __construct(
        private readonly TeamKeyManager $keys,
        private readonly SecretCipher $cipher,
        private readonly WriteVariableVersion $writeVersion,
    ) {}

    /**
     * Upgrade every legacy value, returning how many were rewritten.
     */
    public function handle(): int
    {
        $upgraded = 0;

        VariableVersion::query()
            ->where('ciphertext', 'like', AesGcmSecretCipher::LEGACY_VERSION.'.%')
            ->with('variable.team')
            ->lazyById()
            ->each(function (VariableVersion $version) use (&$upgraded) {
                if (! $this->cipher->isLegacy($version->ciphertext)) {
                    return;
                }

                $team = $version->variable->team;
                $plaintext = $version->reveal();

                DB::transaction(function () use ($version, $team, $plaintext) {
                    $version->forceFill([
                        'ciphertext' => $this->keys->encryptFor(
                            $team,
                            $plaintext,
                            TeamKeyManager::valueContext($team->id, $version->variable_id, $version->version),
                        ),
                        'checksum' => $this->writeVersion->checksum($team, $plaintext),
                        'team_key_version' => $team->currentKey()->version,
                    ])->saveQuietly();
                });

                $upgraded++;
            });

        return $upgraded;
    }
}
