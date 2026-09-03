<?php

namespace App\Models;

use App\Enums\AuditAction;
use App\Enums\WebhookKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Somewhere a team wants to hear about what happens in its vault.
 *
 * The audit trail already records every action worth knowing about. An
 * endpoint is the other half: a trail nobody reads tells you what happened
 * only once you have gone looking, which is usually too late to matter.
 *
 * @property int $id
 * @property int $team_id
 * @property string $name
 * @property WebhookKind $kind
 * @property string $url
 * @property string $signing_secret
 * @property list<string>|null $events
 * @property bool $active
 * @property int|null $created_by
 * @property Carbon|null $last_attempted_at
 * @property int|null $last_status
 * @property string|null $last_error
 * @property int $consecutive_failures
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read User|null $creator
 */
#[Fillable(['team_id', 'name', 'kind', 'url', 'signing_secret', 'events', 'active', 'created_by'])]
#[Hidden(['signing_secret'])]
class WebhookEndpoint extends Model
{
    /**
     * How many failures in a row switch an endpoint off.
     *
     * A URL that stopped existing should stop being tried: a queue that
     * retries a dead endpoint on every audit event forever is a slow way to
     * fill a worker with nothing.
     */
    public const FAILURE_LIMIT = 20;

    /**
     * The model's default attribute values.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'consecutive_failures' => 0,
    ];

    /**
     * Get the team the endpoint belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the user who added the endpoint.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Limit the query to endpoints that are still being delivered to.
     *
     * @param  Builder<WebhookEndpoint>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }

    /**
     * Determine whether this endpoint wants to hear about the given action.
     *
     * An empty filter means everything. That is the useful default for a
     * Slack channel, and it means an action added to the enum later reaches
     * existing endpoints instead of quietly falling outside a list written
     * before it existed.
     */
    public function wants(AuditAction $action): bool
    {
        return $this->events === null
            || $this->events === []
            || in_array($action->value, $this->events, true);
    }

    /**
     * Get the URL as it is safe to show back.
     *
     * A Slack incoming webhook URL is itself the credential to post into the
     * channel, so the settings page shows where an endpoint points without
     * handing the whole thing back to everyone who can open the page.
     */
    public function maskedUrl(): string
    {
        $parts = parse_url($this->url);
        $host = $parts['host'] ?? $this->url;
        $path = $parts['path'] ?? '';

        return 'https://'.$host.(mb_strlen($path) > 12
            ? mb_substr($path, 0, 12).'…'
            : $path);
    }

    /**
     * Sign a delivery body.
     */
    public function sign(string $body): string
    {
        return hash_hmac('sha256', $body, $this->signing_secret);
    }

    /**
     * Record a delivery that arrived.
     */
    public function recordSuccess(int $status): void
    {
        $this->forceFill([
            'last_attempted_at' => now(),
            'last_status' => $status,
            'last_error' => null,
            'consecutive_failures' => 0,
        ])->save();
    }

    /**
     * Record a delivery that did not, and switch the endpoint off once it
     * has failed often enough to be considered gone.
     *
     * A retry that is going to be tried again does not count towards the
     * limit: three attempts at one event are one failed event, and counting
     * them separately would retire a healthy endpoint after a brief outage.
     */
    public function recordFailure(?int $status, string $error, bool $final = true): void
    {
        $failures = $this->consecutive_failures + ($final ? 1 : 0);

        $this->forceFill([
            'last_attempted_at' => now(),
            'last_status' => $status,
            'last_error' => mb_substr($error, 0, 255),
            'consecutive_failures' => $failures,
            'active' => $failures < self::FAILURE_LIMIT,
        ])->save();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => WebhookKind::class,
            'events' => 'array',
            'active' => 'boolean',
            'signing_secret' => 'encrypted',
            'last_attempted_at' => 'datetime',
            'last_status' => 'integer',
            'consecutive_failures' => 'integer',
        ];
    }
}
