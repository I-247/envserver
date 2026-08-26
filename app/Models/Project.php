<?php

namespace App\Models;

use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $team_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read Collection<int, Environment> $environments
 */
#[Fillable(['name', 'slug', 'description'])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    /**
     * Generate a slug that is unique within the given team.
     *
     * Slugs are only assigned on creation and never regenerated: the slug is
     * committed to a repository's kluis.json and used by the CLI, so renaming
     * a project in the portal must not break anyone's deploy.
     */
    public static function generateUniqueSlug(Team $team, string $name): string
    {
        $base = Str::slug($name) ?: 'project';
        $slug = $base;
        $suffix = 1;

        while (static::query()->where('team_id', $team->id)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.++$suffix;
        }

        return $slug;
    }

    /**
     * Get the team that owns the project.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the project's environments, ordered as they are shown in the portal.
     *
     * @return HasMany<Environment, $this>
     */
    public function environments(): HasMany
    {
        return $this->hasMany(Environment::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Get every deploy token issued across the project's environments.
     *
     * A deploy token's last_used_at is the moment a server actually pulled
     * this project's variables, so the newest one across the project is the
     * closest thing we have to "last deployed".
     *
     * @return HasManyThrough<DeployToken, Environment, $this>
     */
    public function deployTokens(): HasManyThrough
    {
        return $this->hasManyThrough(DeployToken::class, Environment::class);
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
