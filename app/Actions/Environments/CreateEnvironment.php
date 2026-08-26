<?php

namespace App\Actions\Environments;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\Environment;
use App\Models\Project;
use App\Models\User;

class CreateEnvironment
{
    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

    /**
     * Add an environment to the project.
     *
     * The new environment starts empty: no variables and no releases. Whoever
     * adds it decides right away whether changes publish themselves, because
     * that choice is what separates a scratch environment from one a server
     * actually pulls from.
     */
    public function handle(Project $project, string $name, bool $autoPublish, ?User $actor = null): Environment
    {
        $environment = $project->environments()->create([
            'name' => $name,
            'slug' => Environment::generateUniqueSlug($project, $name),
            'auto_publish' => $autoPublish,
            'sort_order' => (int) $project->environments()->max('sort_order') + 1,
        ]);

        $this->recordAuditEvent->handle(
            team: $project->team,
            action: AuditAction::EnvironmentCreated,
            actor: $actor,
            subject: $environment,
            metadata: [
                'project' => $project->name,
                'environment' => $environment->name,
                'auto_publish' => $environment->auto_publish,
            ],
        );

        return $environment;
    }
}
