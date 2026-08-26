<?php

namespace App\Actions\Variables;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use App\Models\Variable;
use Illuminate\Support\Facades\DB;

/**
 * Removes a variable from one environment without disturbing the others.
 *
 * A shared variable is never deleted here, not even when the project letting
 * it go is the one that owns it: the projects still using it would lose a
 * value they never asked to lose. Ownership moves instead.
 */
class DetachVariableFromEnvironment
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    /**
     * Detach the variable and re-home it if this was its owner's last hold.
     *
     * @return Project|null the project that inherited the variable, if any
     */
    public function handle(Variable $variable, Environment $environment, ?User $actor = null): ?Project
    {
        return DB::transaction(function () use ($variable, $environment, $actor) {
            $project = $environment->project;
            $team = $project->team;

            $environment->assignments()->where('variable_id', $variable->id)->delete();

            $this->audit->handle($team, AuditAction::VariableDetached, $actor, $variable, [
                'key' => $variable->key,
                'project' => $project->slug,
                'environment' => $environment->slug,
            ]);

            $heir = $this->heirFor($variable->refresh(), $project);

            if ($heir === null) {
                return null;
            }

            $variable->update(['owner_project_id' => $heir->id]);

            $this->audit->handle($team, AuditAction::VariableOwnershipTransferred, $actor, $variable, [
                'key' => $variable->key,
                'from' => $project->slug,
                'to' => $heir->slug,
            ]);

            return $heir;
        });
    }

    /**
     * Work out which project should take the variable over, if any.
     *
     * Only the owner walking away triggers a handover, and only while another
     * project still holds an assignment.
     */
    private function heirFor(Variable $variable, Project $project): ?Project
    {
        if ($variable->owner_project_id !== $project->id) {
            return null;
        }

        if ($variable->usingProjectIds()->contains($project->id)) {
            return null;
        }

        return $variable->heirProject();
    }
}
