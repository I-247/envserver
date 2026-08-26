<?php

namespace App\Actions\Projects;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\Project;
use App\Models\User;
use App\Models\Variable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DeleteProject
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    /**
     * Delete the project and clean up the variables it leaves behind.
     *
     * Deleting the project cascades to its environments, and with them the
     * assignments, releases and deploy tokens. Variables are owned by the
     * team rather than the project, so they survive that cascade even when
     * nothing points at them any more; those are removed here.
     *
     * Variables this project owns but shares with another project are handed
     * over first. Losing a secret because an unrelated project was deleted
     * would be the worst possible surprise, so the handover happens before
     * the delete, while the assignments that prove the sharing still exist.
     *
     * @return list<string> the keys of the variables that were removed
     */
    public function handle(Project $project, ?User $actor = null): array
    {
        return DB::transaction(function () use ($project, $actor) {
            $team = $project->team;
            $projectSlug = $project->slug;

            $candidateIds = Variable::query()
                ->whereHas('assignments', fn ($query) => $query
                    ->whereIn('environment_id', $project->environments()->select('id')))
                ->pluck('id');

            $transferred = $this->handOverSharedVariables($project, $candidateIds, $actor);

            $project->delete();

            /**
             * A variable without assignments can still be pinned by a release
             * of another project it was once attached to. Deleting it would
             * cascade into that release's items and make it unreproducible,
             * so those are deliberately left alone.
             */
            $orphans = Variable::query()
                ->whereIn('id', $candidateIds)
                ->whereDoesntHave('assignments')
                ->whereDoesntHave('releaseItems')
                ->get();

            $removedKeys = array_values($orphans->map(fn (Variable $variable) => $variable->key)->all());

            if ($orphans->isNotEmpty()) {
                Variable::query()->whereIn('id', $orphans->modelKeys())->delete();
            }

            $this->audit->handle($team, AuditAction::ProjectDeleted, $actor, null, [
                'project' => $projectSlug,
                'removed_variables' => $removedKeys,
                'transferred_variables' => $transferred,
            ]);

            return $removedKeys;
        });
    }

    /**
     * Move ownership of shared variables to a project that still uses them.
     *
     * Both routes out of a project share Variable::heirProject(), so
     * deleting a project and detaching its last assignment agree on who
     * inherits.
     *
     * @param  Collection<int, int>  $candidateIds
     * @return list<string> the keys of the variables that changed hands
     */
    private function handOverSharedVariables(Project $project, Collection $candidateIds, ?User $actor): array
    {
        $leaving = $project->environments()->select('id');

        $variables = Variable::query()
            ->whereIn('id', $candidateIds)
            ->where('owner_project_id', $project->id)
            ->whereHas('assignments', fn ($query) => $query->whereNotIn('environment_id', $leaving))
            ->get();

        $transferred = [];

        foreach ($variables as $variable) {
            $heir = $variable->heirProject(excludingEnvironments: $leaving);

            if ($heir === null) {
                continue;
            }

            $variable->update(['owner_project_id' => $heir->id]);

            $this->audit->handle(
                $project->team,
                AuditAction::VariableOwnershipTransferred,
                $actor,
                $variable,
                ['key' => $variable->key, 'from' => $project->slug, 'to' => $heir->slug],
            );

            $transferred[] = $variable->key;
        }

        return $transferred;
    }
}
