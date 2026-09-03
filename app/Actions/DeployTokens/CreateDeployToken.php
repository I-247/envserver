<?php

namespace App\Actions\DeployTokens;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\NewDeployToken;
use App\Enums\AuditAction;
use App\Models\DeployToken;
use App\Models\Environment;
use App\Models\User;
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
     * @param  list<string>  $scopes
     */
    public function handle(
        Environment $environment,
        string $name,
        ?User $creator = null,
        array $scopes = ['env:read'],
        ?Carbon $expiresAt = null,
    ): NewDeployToken {
        return DB::transaction(function () use ($environment, $name, $creator, $scopes, $expiresAt) {
            $client = $this->clients->createClientCredentialsGrantClient(
                $this->clientName($environment, $name),
            );

            $token = DeployToken::create([
                'environment_id' => $environment->id,
                'oauth_client_id' => $client->getKey(),
                'name' => $name,
                'scopes' => $scopes,
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
