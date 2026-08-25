<?php

namespace App\Models;

use Database\Factories\VariableFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A single environment variable owned by a team.
 *
 * The variable itself carries no value: every value lives in an immutable
 * VariableVersion, which is what makes history and rollback possible.
 *
 * @property int $id
 * @property int $team_id
 * @property string $key
 * @property string|null $description
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read User|null $creator
 * @property-read Collection<int, VariableVersion> $versions
 */
#[Fillable(['key', 'description', 'created_by'])]
class Variable extends Model
{
    /** @use HasFactory<VariableFactory> */
    use HasFactory;

    /**
     * Get the team that owns the variable.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the user who first created the variable.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get every version of this variable, newest first.
     *
     * @return HasMany<VariableVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(VariableVersion::class)->orderByDesc('version');
    }

    /**
     * Get the version that new releases would pick up.
     */
    public function currentVersion(): ?VariableVersion
    {
        return $this->versions()->first();
    }

    /**
     * Determine whether the variable is used by more than one environment.
     *
     * Derived rather than stored: a boolean column saying the same thing
     * could drift out of sync the moment an assignment is removed.
     */
    public function isShared(): bool
    {
        return $this->assignments()->count() > 1;
    }

    /**
     * Get the environments this variable is assigned to.
     *
     * @return HasMany<VariableAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(VariableAssignment::class);
    }
}
