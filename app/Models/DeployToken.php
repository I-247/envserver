<?php

namespace App\Models;

use App\Support\IpAllowList;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Binds an OAuth client credentials client to exactly one environment.
 *
 * A deploy server should be able to read one environment and nothing else.
 * OAuth scopes describe what a token may do, not what it may do it to, so
 * the "to what" lives here.
 *
 * @property int $id
 * @property int $environment_id
 * @property string $oauth_client_id
 * @property string $name
 * @property list<string> $scopes
 * @property list<string>|null $ip_allowlist
 * @property int $use_count
 * @property int|null $created_by
 * @property Carbon|null $last_used_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Environment $environment
 * @property-read User|null $creator
 */
#[Fillable(['environment_id', 'oauth_client_id', 'name', 'scopes', 'ip_allowlist', 'created_by', 'expires_at'])]
class DeployToken extends Model
{
    /**
     * The model's default attribute values.
     *
     * Mirrors the column default so a freshly created token reports zero uses
     * instead of null before it has been read back from the database.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'use_count' => 0,
    ];

    /**
     * Get the environment this token may read.
     *
     * @return BelongsTo<Environment, $this>
     */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /**
     * Get the user who created the token.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Determine whether the token was granted the given scope.
     *
     * Checked in addition to the scope on the access token itself: Passport
     * happily issues any application scope to any client, so the allow list
     * here is what actually keeps a read only token read only.
     */
    public function allows(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    /**
     * Get the addresses this token may pull from, on top of the environment's.
     *
     * Empty means the token adds no restriction of its own; the environment's
     * list still applies either way. A token can only narrow, never widen.
     */
    public function ipAllowList(): IpAllowList
    {
        return IpAllowList::make($this->ip_allowlist);
    }

    /**
     * Determine whether the address may use this token.
     *
     * Both lists have to agree: the environment's, which covers every token
     * for it, and the token's own.
     */
    public function allowsAddress(?string $ip): bool
    {
        return $this->environment->ipAllowList()->allows($ip)
            && $this->ipAllowList()->allows($ip);
    }

    /**
     * Determine whether the token may still be used.
     */
    public function isUsable(): bool
    {
        return $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /**
     * Revoke the token permanently.
     *
     * Set directly rather than mass assigned: revoked_at is deliberately not
     * fillable, so it can never be flipped by a stray request payload.
     */
    public function revoke(): void
    {
        $this->forceFill(['revoked_at' => now()])->save();
    }

    /**
     * Record that the token was just used, without touching updated_at.
     *
     * The counter is incremented in the same statement so it can never drift
     * from last_used_at, and in SQL rather than in PHP so two deploys landing
     * at once do not overwrite each other's count.
     */
    public function markUsed(): void
    {
        $this->newQuery()->whereKey($this->getKey())->update([
            'last_used_at' => now(),
            'use_count' => DB::raw('use_count + 1'),
        ]);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'ip_allowlist' => 'array',
            'use_count' => 'integer',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
