<?php

namespace App\Actions\Environments;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\Environment;
use App\Models\User;
use App\Support\IpAllowList;

class UpdateEnvironment
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    /**
     * Rename the environment, set how its changes get published, and set the
     * addresses its deploy tokens may pull from.
     *
     * The slug deliberately stays as it is. Deploy tokens and the CLI address
     * an environment by slug, so regenerating it on a rename would cut off
     * every server already pulling from here.
     */
    public function handle(
        Environment $environment,
        string $name,
        bool $autoPublish,
        ?IpAllowList $allowList = null,
        ?User $actor = null,
    ): Environment {
        $allowList ??= $environment->ipAllowList();

        $before = [
            'name' => $environment->name,
            'auto_publish' => $environment->auto_publish,
            'ip_allowlist' => $environment->ipAllowList()->toArray(),
        ];

        $environment->update([
            'name' => $name,
            'auto_publish' => $autoPublish,
            'ip_allowlist' => $allowList->toStorage(),
        ]);

        // Reopening the modal and pressing save is not an event worth keeping.
        if (! $environment->wasChanged(['name', 'auto_publish', 'ip_allowlist'])) {
            return $environment;
        }

        $this->audit->handle(
            team: $environment->project->team,
            action: AuditAction::EnvironmentUpdated,
            actor: $actor,
            subject: $environment,
            metadata: [
                'project' => $environment->project->slug,
                'environment' => $environment->slug,
                'from' => $before,
                'to' => [
                    'name' => $environment->name,
                    'auto_publish' => $environment->auto_publish,
                    'ip_allowlist' => $allowList->toArray(),
                ],
            ],
        );

        return $environment;
    }
}
