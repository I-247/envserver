<?php

namespace App\Models;

use App\Support\EnvFileRenderer;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An immutable snapshot of everything an environment exposes.
 *
 * Releases are what the CLI pulls. Because each item pins an exact variable
 * version, pulling release 42 a month from now produces byte for byte the
 * same file it did on the day it was published.
 *
 * @property int $id
 * @property int $environment_id
 * @property int $version
 * @property string|null $message
 * @property int|null $published_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Environment $environment
 * @property-read User|null $publisher
 * @property-read Collection<int, ReleaseItem> $items
 */
#[Fillable(['version', 'message', 'published_by'])]
class Release extends Model
{
    /**
     * Get the environment this release belongs to.
     *
     * @return BelongsTo<Environment, $this>
     */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /**
     * Get the user who published this release.
     *
     * @return BelongsTo<User, $this>
     */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    /**
     * Get the pinned entries, in the order they appear in the .env file.
     *
     * @return HasMany<ReleaseItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ReleaseItem::class)->orderBy('key');
    }

    /**
     * Get the release's contents as a map of key to plaintext value.
     *
     * @return array<string, string>
     */
    public function toValueMap(): array
    {
        return $this->items()
            ->with('version.variable.team')
            ->get()
            ->mapWithKeys(fn (ReleaseItem $item) => [$item->key => $item->version->reveal()])
            ->all();
    }

    /**
     * Render the release as the contents of a .env file.
     */
    public function toEnvFile(?string $header = null): string
    {
        return app(EnvFileRenderer::class)->render($this->toValueMap(), $header);
    }

    /**
     * Fingerprint the release so two snapshots can be compared cheaply.
     *
     * @return array<string, int>
     */
    public function fingerprint(): array
    {
        return $this->items()
            ->pluck('variable_version_id', 'key')
            ->all();
    }
}
