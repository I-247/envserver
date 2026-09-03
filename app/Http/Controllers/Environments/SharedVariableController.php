<?php

namespace App\Http\Controllers\Environments;

use App\Actions\Variables\SetVariableShareable;
use App\Actions\Variables\ShareVariableWithEnvironment;
use App\Http\Controllers\Controller;
use App\Http\Requests\Variables\ShareVariableRequest;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Team;
use App\Models\Variable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class SharedVariableController extends Controller
{
    /**
     * List the variables of the team's other projects, newest project first.
     *
     * Loaded as an optional Inertia prop, so the environment page only pays
     * for this when somebody actually opens the share dialog.
     *
     * @return list<array{id: int, key: string, description: string|null, project: string, projectSlug: string, sharedWith: int}>
     */
    public static function shareable(Team $team, Project $project, Environment $environment): array
    {
        $used = $environment->assignments()->pluck('variable_id');

        return array_values(Variable::query()
            ->where('team_id', $team->id)
            ->whereNotNull('owner_project_id')
            ->whereNot('owner_project_id', $project->id)
            // Only what another project deliberately offered. Without this
            // the dialog would be a directory of the team's every secret.
            ->where('shareable', true)
            ->whereNotIn('id', $used)
            /**
             * A variable with no version has no value to share yet; offering
             * it would attach an entry that renders nothing.
             */
            ->whereHas('versions')
            ->with('ownerProject:id,name,slug')
            ->withCount('assignments')
            ->orderBy('key')
            ->get()
            ->map(fn (Variable $variable) => [
                'id' => $variable->id,
                'key' => $variable->key,
                'description' => $variable->description,
                'project' => $variable->ownerProject->name,
                'projectSlug' => $variable->ownerProject->slug,
                'sharedWith' => (int) $variable->getAttribute('assignments_count'),
            ])
            ->all());
    }

    /**
     * Point this environment at a variable another project owns.
     */
    public function store(
        ShareVariableRequest $request,
        Team $currentTeam,
        Project $project,
        Environment $environment,
        ShareVariableWithEnvironment $share,
    ): RedirectResponse {
        Gate::authorize('manageVariables', $project);

        $variable = Variable::query()
            ->where('team_id', $currentTeam->id)
            ->findOrFail((int) $request->validated('variable_id'));

        $share->handle(
            $variable,
            $environment,
            $request->user(),
            $request->validated('alias_key') ?: null,
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Shared :key from :project.', [
                'key' => $variable->key,
                'project' => $variable->ownerProject->name ?? __('another project'),
            ]),
        ]);

        return back();
    }

    /**
     * Offer this project's variable to the team, or withdraw the offer.
     */
    public function update(
        Request $request,
        Team $currentTeam,
        Project $project,
        Environment $environment,
        Variable $variable,
        SetVariableShareable $setShareable,
    ): RedirectResponse {
        Gate::authorize('manageVariables', $project);

        /**
         * Route model binding already scopes the variable to this
         * environment, but not to this project's ownership of it: without
         * this a project could offer up a variable it merely borrowed.
         */
        abort_unless($variable->isOwnedBy($project), 403);

        $shareable = $request->boolean('shareable');

        $setShareable->handle($variable, $project, $shareable, $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $shareable
                ? __(':key can now be used by the team\'s other projects.', ['key' => $variable->key])
                : __(':key is no longer offered. Projects already using it keep it.', ['key' => $variable->key]),
        ]);

        return back();
    }
}
