<?php

namespace App\Models;

use App\Actions\Variables\ResolveEnvironmentVariables;
use App\Data\ResolvedVariable;
use App\Support\IpAllowList;
use Database\Factories\EnvironmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $project_id
 * @property string $name
 * @property string $slug
 * @property bool $auto_publish
 * @property list<string>|null $ip_allowlist
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 * @property-read Collection<int, VariableAssignment> $assignments
 * @property-read Collection<int, Release> $releases
 * @property-read Collection<int, DeployToken> $deployTokens
 */
#[Fillable(['name', 'slug', 'auto_publish', 'ip_allowlist', 'sort_order'])]
class Environment extends Model
{
    /** @use HasFactory<EnvironmentFactory> */
    use HasFactory;

    /**
     * The environments every new project starts with.
     *
     * Production deliberately opts out of auto publishing: a change to a
     * variable that is shared with production should surface as a pending
     * change you promote on purpose, not as a silent new release.
     *
     * @var list<array{name: string, slug: string, auto_publish: bool}>
     */
    public const DEFAULTS = [
        ['name' => 'Development', 'slug' => 'development', 'auto_publish' => true],
        ['name' => 'Staging', 'slug' => 'staging', 'auto_publish' => true],
        ['name' => 'Production', 'slug' => 'production', 'auto_publish' => false],
    ];

    /**
     * Generate a slug that is unique within the given project.
     *
     * Like a project's slug this is only assigned on creation and never
     * regenerated: the slug ends up in a deploy token's URL and in the CLI's
     * configuration, so renaming an environment must not move it.
     */
    public static function generateUniqueSlug(Project $project, string $name): string
    {
        $base = Str::slug($name) ?: 'environment';
        $slug = $base;
        $suffix = 1;

        while (static::query()->where('project_id', $project->id)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.++$suffix;
        }

        return $slug;
    }

    /**
     * Get the project that owns the environment.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the variables assigned to this environment.
     *
     * @return HasMany<VariableAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(VariableAssignment::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Get the variables assigned to this environment.
     *
     * Route model binding scopes {variable} through this relation, so a URL
     * can only ever address a variable this environment actually uses.
     *
     * @return BelongsToMany<Variable, $this>
     */
    public function variables(): BelongsToMany
    {
        return $this->belongsToMany(Variable::class, 'variable_assignments')
            ->withPivot(['alias_key', 'sort_order'])
            ->withTimestamps();
    }

    /**
     * Get the deploy tokens issued for this environment.
     *
     * @return HasMany<DeployToken, $this>
     */
    public function deployTokens(): HasMany
    {
        return $this->hasMany(DeployToken::class);
    }

    /**
     * Get the environment's releases, newest first.
     *
     * @return HasMany<Release, $this>
     */
    public function releases(): HasMany
    {
        return $this->hasMany(Release::class)->orderByDesc('version');
    }

    /**
     * Get the release the CLI would serve by default.
     */
    public function latestRelease(): ?Release
    {
        return $this->releases()->first();
    }

    /**
     * Determine whether the current variables differ from the last release.
     *
     * For an environment with auto_publish off this is the signal that
     * something is waiting to be promoted on purpose.
     */
    public function hasPendingChanges(): bool
    {
        $resolved = app(ResolveEnvironmentVariables::class)
            ->handle($this)
            ->mapWithKeys(fn (ResolvedVariable $entry) => [$entry->key => $entry->version->id])
            ->all();

        return ($this->latestRelease()?->fingerprint() ?? []) !== $resolved;
    }

    /**
     * Get the addresses a deploy token for this environment may pull from.
     *
     * Empty means unrestricted. The list guards machine access only: people
     * reading the environment in the browser or with the CLI are already
     * covered by the sign in allow lists.
     */
    public function ipAllowList(): IpAllowList
    {
        return IpAllowList::make($this->ip_allowlist);
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'auto_publish' => 'boolean',
            'ip_allowlist' => 'array',
        ];
    }
}
