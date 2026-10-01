<?php

namespace App\Actions\DeployTokens;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\DeployToken;
use App\Models\User;
use App\Support\IpAllowList;

class UpdateDeployTokenAllowList
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    /**
     * Set the addresses a deploy token may pull from.
     *
     * Moving a deploy server to a new address should not mean issuing a new
     * secret and editing every deploy script, so the list can change after
     * the token exists. Both the old and the new list go into the audit
     * trail; neither is a secret.
     */
    public function handle(DeployToken $deployToken, IpAllowList $allowList, ?User $actor = null): DeployToken
    {
        $before = $deployToken->ipAllowList()->toArray();

        $deployToken->update(['ip_allowlist' => $allowList->toStorage()]);

        // Reopening the dialog and pressing save is not an event worth keeping.
        if (! $deployToken->wasChanged('ip_allowlist')) {
            return $deployToken;
        }

        $environment = $deployToken->environment;

        $this->audit->handle(
            team: $environment->project->team,
            action: AuditAction::DeployTokenUpdated,
            actor: $actor,
            subject: $deployToken,
            metadata: [
                'name' => $deployToken->name,
                'project' => $environment->project->slug,
                'environment' => $environment->slug,
                'from' => $before,
                'to' => $allowList->toArray(),
            ],
        );

        return $deployToken;
    }
}
