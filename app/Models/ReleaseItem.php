<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One pinned entry within a release.
 *
 * @property int $id
 * @property int $release_id
 * @property int $variable_id
 * @property int $variable_version_id
 * @property string $key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Release $release
 * @property-read Variable $variable
 * @property-read VariableVersion $version
 */
#[Fillable(['variable_id', 'variable_version_id', 'key'])]
class ReleaseItem extends Model
{
    /**
     * Get the release this entry belongs to.
     *
     * @return BelongsTo<Release, $this>
     */
    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class);
    }

    /**
     * Get the variable this entry points at.
     *
     * @return BelongsTo<Variable, $this>
     */
    public function variable(): BelongsTo
    {
        return $this->belongsTo(Variable::class);
    }

    /**
     * Get the exact version this entry pinned.
     *
     * @return BelongsTo<VariableVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(VariableVersion::class, 'variable_version_id');
    }
}
