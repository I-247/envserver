<?php

namespace App\Models;

use App\Cryptography\TeamKeyManager;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One immutable value of a variable.
 *
 * Versions are append only. Updating a variable writes a new row; nothing
 * ever overwrites a previous value, which is what lets a release pin an exact
 * version and lets a rollback be a lookup instead of a reconstruction.
 *
 * @property int $id
 * @property int $variable_id
 * @property int $version
 * @property string $ciphertext
 * @property string $checksum
 * @property int $team_key_version
 * @property int|null $author_id
 * @property string|null $note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Variable $variable
 * @property-read User|null $author
 */
#[Fillable(['version', 'ciphertext', 'checksum', 'team_key_version', 'author_id', 'note'])]
#[Hidden(['ciphertext', 'checksum'])]
class VariableVersion extends Model
{
    /**
     * Get the variable this version belongs to.
     *
     * @return BelongsTo<Variable, $this>
     */
    public function variable(): BelongsTo
    {
        return $this->belongsTo(Variable::class);
    }

    /**
     * Get the user who wrote this version.
     *
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * Decrypt and return the stored value.
     *
     * Named reveal() rather than exposed as an attribute on purpose: reading
     * a secret should be an explicit act at the call site, never something
     * that happens by accident through serialization or a debug dump.
     */
    public function reveal(): string
    {
        return app(TeamKeyManager::class)->decryptFor(
            $this->variable->team,
            $this->ciphertext,
        );
    }
}
