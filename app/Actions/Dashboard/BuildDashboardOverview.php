<?php

namespace App\Actions\Dashboard;

use App\Actions\Releases\DiffReleases;
use App\Actions\Variables\ReviewSecretAge;
use App\Cryptography\AesGcmSecretCipher;
use App\Data\SecretAge;
use App\Models\AuditEvent;
use App\Models\DeployToken;
use App\Models\Environment;
use App\Models\Release;
use App\Models\Team;
use Illuminate\Database\Eloquent\Builder;

/**
 * Gathers everything the dashboard widgets show for one team.
 *
 * Kept out of the controller because the "waiting to be published" widget is
 * the only place in the portal that has to resolve variables for more than
 * one environment at a time, and that deserves its own guard rails.
 */
class BuildDashboardOverview
{
    /**
     * How many manual environments are resolved for pending changes.
     *
     * Resolving costs a handful of queries per environment, so the dashboard
     * looks at a bounded slice rather than at every environment a large team
     * owns. The environment pages themselves remain the complete view.
     */
    private const PENDING_SCAN_LIMIT = 25;

    /**
     * How many rows each list widget shows.
     */
    private const ROWS = 5;

    public function __construct(
        private readonly DiffReleases $diff,
        private readonly ReviewSecretAge $secretAge,
    ) {}

    /**
     * Build the payload behind every dashboard widget.
     *
     * The audit widget is opt in rather than always present: the trail records
     * who read which secret, so the caller passes the outcome of the viewAudit
     * gate and a member simply never receives the key.
     *
     * @return array{
     *     stats: array{projects: int, environments: int, variables: int, deployTokens: int},
     *     pendingEnvironments: list<array{project: array{name: string, slug: string}, environment: array{name: string, slug: string}, changes: int, version: int|null}>,
     *     recentReleases: list<array{id: int, version: int, message: string|null, project: array{name: string, slug: string}, environment: array{name: string, slug: string}, publishedBy: string|null, publishedAt: string|null, variablesCount: int}>,
     *     deployTokens: list<array{name: string, project: string, environment: string, lastUsedAt: string|null, expiresAt: string|null}>,
     *     staleSecrets: array{total: int, rows: list<array{key: string, project: string|null, overdueByDays: int, intervalDays: int|null, rotatedAt: string|null}>},
     *     recentActivity: list<array{id: int, label: string, actor: string|null, createdAt: string|null}>|null,
     *     encryption: array{cipher: string, scheme: string, keyVersion: int|null, keyCreatedAt: string|null},
     * }
     */
    public function handle(Team $team, bool $withActivity = false): array
    {
        return [
            'stats' => $this->stats($team),
            'pendingEnvironments' => $this->pendingEnvironments($team),
            'recentReleases' => $this->recentReleases($team),
            'deployTokens' => $this->deployTokens($team),
            'staleSecrets' => $this->staleSecrets($team),
            'recentActivity' => $withActivity ? $this->recentActivity($team) : null,
            'encryption' => $this->encryption($team),
        ];
    }

    /**
     * Count the things the team owns.
     *
     * @return array{projects: int, environments: int, variables: int, deployTokens: int}
     */
    private function stats(Team $team): array
    {
        return [
            'projects' => $team->projects()->count(),
            'environments' => $this->environmentIds($team)->count(),
            'variables' => $team->variables()->count(),
            'deployTokens' => $this->usableTokens($team)->count(),
        ];
    }

    /**
     * List the manual environments whose variables drifted from their last
     * release, newest project first.
     *
     * Auto publishing environments are skipped on purpose: a change there
     * already produced a release, so they can never be waiting on anyone.
     *
     * @return list<array{project: array{name: string, slug: string}, environment: array{name: string, slug: string}, changes: int, version: int|null}>
     */
    private function pendingEnvironments(Team $team): array
    {
        return array_values(Environment::query()
            ->with('project')
            ->whereIn('project_id', $team->projects()->select('id'))
            ->where('auto_publish', false)
            ->orderByDesc('id')
            ->limit(self::PENDING_SCAN_LIMIT)
            ->get()
            ->map(fn (Environment $environment) => [
                'project' => [
                    'name' => $environment->project->name,
                    'slug' => $environment->project->slug,
                ],
                'environment' => [
                    'name' => $environment->name,
                    'slug' => $environment->slug,
                ],
                'changes' => count($this->diff->pending($environment, reveal: false)),
                'version' => $environment->latestRelease()?->version,
            ])
            ->filter(fn (array $row) => $row['changes'] > 0)
            ->take(self::ROWS)
            ->all());
    }

    /**
     * List the team's most recent releases across every project.
     *
     * @return list<array{id: int, version: int, message: string|null, project: array{name: string, slug: string}, environment: array{name: string, slug: string}, publishedBy: string|null, publishedAt: string|null, variablesCount: int}>
     */
    private function recentReleases(Team $team): array
    {
        return array_values(Release::query()
            ->with(['environment.project', 'publisher'])
            ->withCount('items')
            ->whereIn('environment_id', $this->environmentIds($team))
            ->latest('id')
            ->limit(self::ROWS)
            ->get()
            ->map(fn (Release $release) => [
                'id' => $release->id,
                'version' => $release->version,
                'message' => $release->message,
                'project' => [
                    'name' => $release->environment->project->name,
                    'slug' => $release->environment->project->slug,
                ],
                'environment' => [
                    'name' => $release->environment->name,
                    'slug' => $release->environment->slug,
                ],
                'publishedBy' => $release->publisher?->name,
                'publishedAt' => $release->created_at?->toISOString(),
                'variablesCount' => $release->items_count,
            ])
            ->all());
    }

    /**
     * List the usable deploy tokens that want attention first: the ones about
     * to expire, then the ones no deployment ever used.
     *
     * @return list<array{name: string, project: string, environment: string, lastUsedAt: string|null, expiresAt: string|null}>
     */
    private function deployTokens(Team $team): array
    {
        return array_values($this->usableTokens($team)
            ->with('environment.project')
            ->orderByRaw('expires_at is null')
            ->orderBy('expires_at')
            ->orderByRaw('last_used_at is not null')
            ->limit(self::ROWS)
            ->get()
            ->map(fn (DeployToken $token) => [
                'name' => $token->name,
                'project' => $token->environment->project->name,
                'environment' => $token->environment->name,
                'lastUsedAt' => $token->last_used_at?->toISOString(),
                'expiresAt' => $token->expires_at?->toISOString(),
            ])
            ->all());
    }

    /**
     * List the secrets that have stood longer than the policy allows.
     *
     * The total is reported next to the rows rather than only a top five:
     * "three overdue" and "three hundred overdue" are different situations,
     * and a list capped at five looks identical in both.
     *
     * @return array{total: int, rows: list<array{key: string, project: string|null, overdueByDays: int, intervalDays: int|null, rotatedAt: string|null}>}
     */
    private function staleSecrets(Team $team): array
    {
        $overdue = $this->secretAge->overdue($team);

        return [
            'total' => $overdue->count(),
            'rows' => array_values($overdue
                ->take(self::ROWS)
                ->map(fn (SecretAge $age) => [
                    'key' => $age->variable->key,
                    'project' => $age->variable->ownerProject?->name,
                    'overdueByDays' => $age->overdueByDays(),
                    'intervalDays' => $age->intervalDays,
                    'rotatedAt' => $age->rotatedAt?->toISOString(),
                ])
                ->all()),
        ];
    }

    /**
     * List the last few audit entries.
     *
     * @return list<array{id: int, label: string, actor: string|null, createdAt: string|null}>
     */
    private function recentActivity(Team $team): array
    {
        return array_values($team->auditEvents()
            ->latest('id')
            ->limit(self::ROWS)
            ->get()
            ->map(fn (AuditEvent $event) => [
                'id' => $event->id,
                'label' => $event->action->label(),
                'actor' => $event->actor_name,
                'createdAt' => $event->created_at?->toISOString(),
            ])
            ->all());
    }

    /**
     * Describe how this team's secrets are stored.
     *
     * Reads the team key straight from the relation instead of going through
     * TeamKeyManager: asking the manager for a data key provisions one when
     * the team has none, and rendering a dashboard should never write a key.
     * A team that has not stored a secret yet simply reports no key version.
     *
     * @return array{cipher: string, scheme: string, keyVersion: int|null, keyCreatedAt: string|null}
     */
    private function encryption(Team $team): array
    {
        $key = $team->currentKey();

        return [
            'cipher' => 'AES-256-GCM',
            'scheme' => $key->algorithm ?? AesGcmSecretCipher::VERSION,
            'keyVersion' => $key?->version,
            'keyCreatedAt' => $key?->created_at?->toISOString(),
        ];
    }

    /**
     * Query the tokens that a deploy server could still exchange today.
     *
     * @return Builder<DeployToken>
     */
    private function usableTokens(Team $team): Builder
    {
        return DeployToken::query()
            ->whereIn('environment_id', $this->environmentIds($team))
            ->whereNull('revoked_at')
            ->where(fn ($query) => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>=', now()));
    }

    /**
     * Sub query selecting every environment id the team owns.
     *
     * @return Builder<Environment>
     */
    private function environmentIds(Team $team): Builder
    {
        return Environment::query()
            ->select('id')
            ->whereIn('project_id', $team->projects()->select('id'));
    }
}
