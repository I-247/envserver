<?php

namespace App\Actions\Environments;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use App\Models\Variable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DeleteEnvironment
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    /**
     * Delete the environment and clean up the variables it leaves behind.
     *
     * The delete cascades to the environment's assignments, releases and
     * deploy tokens. Variables belong to the team rather than to one
     * environment, so they survive that cascade; the ones nothing points at
     * any more are removed here, the same way DeleteProject does it.
     *
     * @return list<string> the keys of the variables that were removed
     */
    public function handle(Environment $environment, ?User $actor = null): array
    {
        return DB::transaction(function () use ($environment, $actor) {
            $project = $environment->project;
            $team = $project->team;

            $candidateIds = Variable::query()
                ->whereHas('assignments', fn ($query) => $query->where('environment_id', $environment->id))
                ->pluck('id');

            $transferred = $this->handOverSharedVariables($environment, $candidateIds, $actor);

            $environment->delete();

            /**
             * A variable without assignments can still be pinned by a release
             * of another environment. Deleting it would cascade into that
             * release's items and make it unreproducible, so it stays.
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

            $this->audit->handle($team, AuditAction::EnvironmentDeleted, $actor, null, [
                'project' => $project->slug,
                'environment' => $environment->slug,
                'removed_variables' => $removedKeys,
                'transferred_variables' => $transferred,
            ]);

            return $removedKeys;
        });
    }

    /**
     * Move ownership of variables this environment was the last hold on.
     *
     * Unlike a project delete, the owning project usually survives this: as
     * long as one of its other environments still uses the variable it keeps
     * it, which is why an heir pointing back at the same project is no
     * handover at all.
     *
     * @param  Collection<int, int>  $candidateIds
     * @return list<string> the keys of the variables that changed hands
     */
    private function handOverSharedVariables(Environment $environment, Collection $candidateIds, ?User $actor): array
    {
        $project = $environment->project;
        $leaving = [$environment->id];

        $variables = Variable::query()
            ->whereIn('id', $candidateIds)
            ->where('owner_project_id', $project->id)
            ->whereHas('assignments', fn ($query) => $query->whereNotIn('environment_id', $leaving))
            ->get();

        $transferred = [];

        foreach ($variables as $variable) {
            $heir = $variable->heirProject(excludingEnvironments: $leaving);

            if ($heir === null || $heir->is($project)) {
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
