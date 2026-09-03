<?php

namespace App\Models;

use Database\Factories\VariableFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;

/**
 * A single environment variable owned by a team.
 *
 * The variable itself carries no value: every value lives in an immutable
 * VariableVersion, which is what makes history and rollback possible.
 *
 * @property int $id
 * @property int $team_id
 * @property int|null $owner_project_id
 * @property bool $shareable
 * @property string $key
 * @property string|null $description
 * @property int|null $rotate_after_days
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read Project|null $ownerProject
 * @property-read User|null $creator
 * @property-read Collection<int, VariableVersion> $versions
 * @property-read Collection<int, VariableAssignment> $assignments
 * @property-read Collection<int, ReleaseItem> $releaseItems
 */
#[Fillable(['key', 'description', 'rotate_after_days', 'created_by', 'owner_project_id', 'shareable'])]
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
     * Get the project responsible for the variable.
     *
     * Owning a variable is not the same as using one: every project with an
     * assignment reads the value, but only the owner is presented as the
     * place it lives, and only the owner's deletion hands it on.
     *
     * @return BelongsTo<Project, $this>
     */
    public function ownerProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'owner_project_id');
    }

    /**
     * Get every project that uses this variable, owner included.
     *
     * @return HasManyThrough<Project, VariableAssignment, $this>
     */
    public function projects(): HasManyThrough
    {
        return $this->hasManyThrough(
            Project::class,
            VariableAssignment::class,
            'variable_id',
            'id',
            'id',
            'environment_id',
        )->join('environments', 'environments.id', '=', 'variable_assignments.environment_id')
            ->select('projects.*')
            ->distinct();
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
     * Determine whether more than one project uses this variable.
     *
     * Narrower than isShared(): two environments of a single project also
     * make a variable shared, but only a second project makes removing it
     * somebody else's problem.
     */
    public function isSharedAcrossProjects(): bool
    {
        return $this->usingProjectIds()->count() > 1;
    }

    /**
     * Determine whether the given project may offer this variable to others.
     *
     * Only the owner decides. A project that borrowed the variable cannot
     * pass it on, which keeps one project answerable for where it spreads.
     */
    public function isOwnedBy(Project $project): bool
    {
        return $this->owner_project_id === $project->id;
    }

    /**
     * Determine whether another project is allowed to pick this variable up.
     */
    public function isOfferedToOtherProjects(): bool
    {
        return $this->shareable && $this->owner_project_id !== null;
    }

    /**
     * Determine whether the given project merely borrows this variable.
     */
    public function isBorrowedBy(Project $project): bool
    {
        return $this->owner_project_id !== null
            && $this->owner_project_id !== $project->id;
    }

    /**
     * Get the ids of the projects currently assigned this variable.
     *
     * Read from the assignments rather than the relation so it reflects
     * deletions made in the same transaction.
     *
     * @return SupportCollection<int, int>
     */
    public function usingProjectIds(): SupportCollection
    {
        return Environment::query()
            ->whereIn('id', $this->assignments()->select('environment_id'))
            ->distinct()
            ->pluck('project_id');
    }

    /**
     * Get the project that should take this variable over.
     *
     * The oldest surviving assignment wins: it belongs to the project that
     * has lived with the variable longest, and ordering by it keeps the
     * outcome independent of the order rows happen to come back in.
     *
     * Built off Project so the result really is a Project. Selecting
     * projects.* from the assignments table instead would hand back a
     * VariableAssignment wearing a project's columns, and its id would be
     * the assignment's.
     *
     * @param  list<int>|Builder<Environment>|Relation<Environment, *, *>|null  $excludingEnvironments  environments about to disappear
     */
    public function heirProject(array|Builder|Relation|null $excludingEnvironments = null): ?Project
    {
        return Project::query()
            ->join('environments', 'environments.project_id', '=', 'projects.id')
            ->join('variable_assignments', 'variable_assignments.environment_id', '=', 'environments.id')
            ->where('variable_assignments.variable_id', $this->id)
            ->when(
                $excludingEnvironments !== null,
                fn (Builder $query) => $query->whereNotIn('environments.id', $excludingEnvironments),
            )
            ->orderBy('variable_assignments.id')
            ->select('projects.*')
            ->first();
    }

    /**
     * Get the number of days this variable may go unchanged.
     *
     * The team's default applies unless the variable names its own interval.
     * Null at both levels means no policy, which is not the same as "never
     * rotate": nothing is claimed about the variable at all, so nothing is
     * reported about it either.
     */
    public function rotationInterval(): ?int
    {
        return $this->rotate_after_days ?? $this->team->default_rotate_after_days;
    }

    /**
     * Get every release entry that pins a version of this variable.
     *
     * Releases outlive assignments: a variable detached from an environment
     * is still part of the releases that shipped it, which is what makes an
     * old release reproducible.
     *
     * @return HasMany<ReleaseItem, $this>
     */
    public function releaseItems(): HasMany
    {
        return $this->hasMany(ReleaseItem::class);
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

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'shareable' => 'boolean',
            'rotate_after_days' => 'integer',
        ];
    }
}
