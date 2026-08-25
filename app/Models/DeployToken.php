<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

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
 * @property int|null $created_by
 * @property Carbon|null $last_used_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Environment $environment
 * @property-read User|null $creator
 */
#[Fillable(['environment_id', 'oauth_client_id', 'name', 'scopes', 'created_by', 'expires_at'])]
class DeployToken extends Model
{
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
     */
    public function markUsed(): void
    {
        $this->newQuery()->whereKey($this->getKey())->update(['last_used_at' => now()]);
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
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
