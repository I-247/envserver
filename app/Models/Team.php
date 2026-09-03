<?php

namespace App\Models;

use App\Concerns\GeneratesUniqueTeamSlugs;
use App\Enums\TeamRole;
use App\Support\IpAllowList;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Fortify;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property bool $is_personal
 * @property list<string>|null $ip_allowlist
 * @property bool $two_factor_required
 * @property int|null $default_rotate_after_days
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, TeamInvitation> $invitations
 * @property-read Collection<int, Membership> $memberships
 * @property-read Collection<int, Project> $projects
 * @property-read Collection<int, TeamKey> $keys
 * @property-read Collection<int, Variable> $variables
 * @property-read Collection<int, User> $members
 */
#[Fillable(['name', 'slug', 'is_personal', 'ip_allowlist', 'two_factor_required', 'default_rotate_after_days'])]
class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use GeneratesUniqueTeamSlugs, HasFactory, SoftDeletes;

    /**
     * Bootstrap the model and its traits.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Team $team) {
            if (empty($team->slug)) {
                $team->slug = static::generateUniqueTeamSlug($team->name);
            }
        });

        static::updating(function (Team $team) {
            if ($team->isDirty('name')) {
                $team->slug = static::generateUniqueTeamSlug($team->name, $team->id);
            }
        });
    }

    /**
     * Get the team owner.
     */
    public function owner(): ?Model
    {
        return $this->members()
            ->wherePivot('role', TeamRole::Owner->value)
            ->first();
    }

    /**
     * Get all members of this team.
     *
     * @return BelongsToMany<User, $this, Membership, 'pivot'>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_members', 'team_id', 'user_id')
            ->using(Membership::class)
            ->withPivot(['role'])
            ->withTimestamps();
    }

    /**
     * Get all memberships for this team.
     *
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * Get all data encryption keys ever issued for this team.
     *
     * @return HasMany<TeamKey, $this>
     */
    public function keys(): HasMany
    {
        return $this->hasMany(TeamKey::class);
    }

    /**
     * Get the team's active data encryption key, if it has one yet.
     */
    public function currentKey(): ?TeamKey
    {
        return $this->keys()
            ->whereNull('retired_at')
            ->orderByDesc('version')
            ->first();
    }

    /**
     * Get all projects belonging to this team.
     *
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * Get the team's audit trail.
     *
     * @return HasMany<AuditEvent, $this>
     */
    public function auditEvents(): HasMany
    {
        return $this->hasMany(AuditEvent::class);
    }

    /**
     * Get all variables owned by this team.
     *
     * @return HasMany<Variable, $this>
     */
    public function variables(): HasMany
    {
        return $this->hasMany(Variable::class);
    }

    /**
     * Get all invitations for this team.
     *
     * @return HasMany<TeamInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(TeamInvitation::class);
    }

    /**
     * Get the endpoints this team sends its audit events to.
     *
     * @return HasMany<WebhookEndpoint, $this>
     */
    public function webhookEndpoints(): HasMany
    {
        return $this->hasMany(WebhookEndpoint::class)->orderBy('name');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_personal' => 'boolean',
            'ip_allowlist' => 'array',
            'two_factor_required' => 'boolean',
            'default_rotate_after_days' => 'integer',
        ];
    }

    /**
     * Get the addresses this team may be reached from.
     *
     * An empty list means the team adds no restriction of its own; the
     * operator's list in config still applies either way.
     */
    public function ipAllowList(): IpAllowList
    {
        return IpAllowList::make($this->ip_allowlist);
    }

    /**
     * Get the members who cannot reach this team while it requires a second factor.
     *
     * Mirrors User::hasSecondFactor() as a query, Fortify's confirmation
     * setting included, so the two never disagree about who is enrolled.
     *
     * @return BelongsToMany<User, $this, Membership, 'pivot'>
     */
    public function membersWithoutSecondFactor(): BelongsToMany
    {
        return $this->members()
            ->where(fn (Builder $query) => Fortify::confirmsTwoFactorAuthentication()
                ? $query->whereNull('users.two_factor_confirmed_at')
                : $query->whereNull('users.two_factor_secret'))
            ->whereDoesntHave('passkeys');
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
