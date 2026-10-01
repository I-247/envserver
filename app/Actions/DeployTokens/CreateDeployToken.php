<?php

namespace App\Actions\DeployTokens;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\NewDeployToken;
use App\Enums\AuditAction;
use App\Models\DeployToken;
use App\Models\Environment;
use App\Models\User;
use App\Support\IpAllowList;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\ClientRepository;

class CreateDeployToken
{
    public function __construct(
        private readonly ClientRepository $clients,
        private readonly RecordAuditEvent $audit,
    ) {}

    /**
     * Issue a machine token that may read exactly one environment.
     *
     * The allow list narrows where the token may be used from, on top of the
     * environment's own list. Left out, the token adds no restriction.
     *
     * @param  list<string>  $scopes
     */
    public function handle(
        Environment $environment,
        string $name,
        ?User $creator = null,
        array $scopes = ['env:read'],
        ?Carbon $expiresAt = null,
        ?IpAllowList $allowList = null,
    ): NewDeployToken {
        $allowList ??= IpAllowList::make([]);

        return DB::transaction(function () use ($environment, $name, $creator, $scopes, $expiresAt, $allowList) {
            $client = $this->clients->createClientCredentialsGrantClient(
                $this->clientName($environment, $name),
            );

            $token = DeployToken::create([
                'environment_id' => $environment->id,
                'oauth_client_id' => $client->getKey(),
                'name' => $name,
                'scopes' => $scopes,
                'ip_allowlist' => $allowList->toStorage(),
                'created_by' => $creator?->id,
                'expires_at' => $expiresAt,
            ]);

            $this->audit->handle(
                $environment->project->team,
                AuditAction::DeployTokenCreated,
                $creator,
                $token,
                [
                    'name' => $name,
                    'project' => $environment->project->slug,
                    'environment' => $environment->slug,
                    'scopes' => $scopes,
                    'ip_allowlist' => $allowList->toArray(),
                ],
            );

            return new NewDeployToken($token, (string) $client->getKey(), (string) $client->plainSecret);
        });
    }

    /**
     * Build a client name that is recognisable in the OAuth client list.
     */
    private function clientName(Environment $environment, string $name): string
    {
        $project = $environment->project;

        return "{$project->slug}/{$environment->slug} — {$name}";
    }
}
