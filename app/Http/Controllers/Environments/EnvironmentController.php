<?php

namespace App\Http\Controllers\Environments;

use App\Actions\Environments\CreateEnvironment;
use App\Actions\Environments\DeleteEnvironment;
use App\Actions\Environments\UpdateEnvironment;
use App\Actions\Releases\DiffReleases;
use App\Actions\Variables\ResolveEnvironmentVariables;
use App\Data\ResolvedVariable;
use App\Data\SecretAge;
use App\Data\VariableChange;
use App\Http\Controllers\Controller;
use App\Http\Requests\Environments\SaveEnvironmentRequest;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Team;
use App\Models\Variable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class EnvironmentController extends Controller
{
    /**
     * Add an environment to the project.
     */
    public function store(
        SaveEnvironmentRequest $request,
        Team $currentTeam,
        Project $project,
        CreateEnvironment $createEnvironment,
    ): RedirectResponse {
        Gate::authorize('update', $project);

        $environment = $createEnvironment->handle(
            $project,
            $request->validated('name'),
            $request->boolean('auto_publish'),
            $request->user(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Environment created.')]);

        return to_route('environments.show', [
            'current_team' => $currentTeam->slug,
            'project' => $project->slug,
            'environment' => $environment->slug,
        ]);
    }

    /**
     * Show an environment with its variables and anything waiting to be
     * published.
     */
    public function show(
        Request $request,
        Team $currentTeam,
        Project $project,
        Environment $environment,
        ResolveEnvironmentVariables $resolve,
        DiffReleases $diff,
    ): Response {
        Gate::authorize('view', $project);

        $user = $request->user();
        $canReveal = $user->can('viewSecrets', $project);

        return Inertia::render('environments/show', [
            'project' => ['name' => $project->name, 'slug' => $project->slug],
            'server' => config('app.url'),
            'environment' => [
                'name' => $environment->name,
                'slug' => $environment->slug,
                'autoPublish' => $environment->auto_publish,
                'ipAllowList' => $environment->ipAllowList()->toArray(),
            ],
            // No plaintext anywhere in this payload, not in the table and not
            // in the diff below: the page must be safe to leave open on a
            // shared screen, and revealing a value should be a decision that
            // the server gets to see.
            'variables' => $resolve->handle($environment)->map(fn (ResolvedVariable $entry) => [
                'id' => $entry->variable->id,
                'key' => $entry->key,
                'ownKey' => $entry->variable->key,
                'alias' => $entry->key === $entry->variable->key ? null : $entry->key,
                'description' => $entry->variable->description,
                'shared' => $entry->shared,
                'sharedWith' => $entry->shared ? $entry->variable->assignments()->count() : 1,
                // Which project answers for this value, and whether that is
                // us. A borrowed variable is edited where it lives, so the
                // table links back to its owner instead of offering a field.
                'owner' => $this->owner($entry->variable, $project),
                'borrowed' => $entry->variable->isBorrowedBy($project),
                // Only the owner may change the offer, so the toggle is only
                // rendered where it would actually be accepted.
                'shareable' => $entry->variable->shareable,
                'canOffer' => $entry->variable->isOwnedBy($project),
                'version' => $entry->version->version,
                'updatedAt' => $entry->version->created_at?->toISOString(),
                // How long this value has been standing, against the interval
                // set for it. The version date was already here; what is new
                // is that the page can say whether it is late.
                'rotation' => $this->rotation(
                    SecretAge::for($entry->variable, $entry->version->created_at, $currentTeam->default_rotate_after_days),
                ),
            ]),
            'shareable' => Inertia::optional(
                fn () => SharedVariableController::shareable($currentTeam, $project, $environment),
            ),
            'pending' => array_map(
                fn (VariableChange $change) => [
                    'key' => $change->key,
                    'type' => $change->type->value,
                    'before' => $change->before,
                    'after' => $change->after,
                ],
                $diff->pending($environment, reveal: false),
            ),
            'latestRelease' => $environment->latestRelease()?->only(['version', 'message']),
            'permissions' => [
                'canManageVariable' => $user->can('manageVariables', $project),
                'canViewSecretValue' => $canReveal,
                'canPublishRelease' => $user->can('publishReleases', $project),
                'canManageDeployToken' => $user->can('manageDeployTokens', $project),
                'canManageEnvironment' => $user->can('update', $project),
                'canDeleteEnvironment' => $user->can('delete', $project),
            ],
        ]);
    }

    /**
     * Describe how a value stands against its rotation interval.
     *
     * @return array{intervalDays: int|null, ownIntervalDays: int|null, dueAt: string|null, overdueByDays: int}
     */
    private function rotation(SecretAge $age): array
    {
        return [
            'intervalDays' => $age->intervalDays,
            // Told apart from the effective interval so the form can show an
            // empty field that means "follow the team" rather than a number
            // the variable never chose.
            'ownIntervalDays' => $age->variable->rotate_after_days,
            'dueAt' => $age->dueAt()?->toISOString(),
            'overdueByDays' => $age->overdueByDays(),
        ];
    }

    /**
     * Describe the project a variable belongs to.
     *
     * @return array{name: string, slug: string}|null
     */
    private function owner(Variable $variable, Project $project): ?array
    {
        $owner = $variable->owner_project_id === $project->id
            ? $project
            : $variable->ownerProject;

        return $owner === null
            ? null
            : ['name' => $owner->name, 'slug' => $owner->slug];
    }

    /**
     * Rename the environment or change how it publishes.
     */
    public function update(
        SaveEnvironmentRequest $request,
        Team $currentTeam,
        Project $project,
        Environment $environment,
        UpdateEnvironment $updateEnvironment,
    ): RedirectResponse {
        Gate::authorize('update', $project);

        $updateEnvironment->handle(
            $environment,
            $request->validated('name'),
            $request->boolean('auto_publish'),
            $request->allowList(),
            $request->user(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Environment updated.')]);

        return to_route('environments.show', [
            'current_team' => $currentTeam->slug,
            'project' => $project->slug,
            'environment' => $environment->slug,
        ]);
    }

    /**
     * Delete the environment along with the variables it leaves behind.
     */
    public function destroy(
        Request $request,
        Team $currentTeam,
        Project $project,
        Environment $environment,
        DeleteEnvironment $deleteEnvironment,
    ): RedirectResponse {
        Gate::authorize('delete', $project);

        $removedVariables = $deleteEnvironment->handle($environment, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => $removedVariables === []
            ? __('Environment deleted.')
            : trans_choice('Environment deleted, along with :count unused variable.|Environment deleted, along with :count unused variables.', count($removedVariables), ['count' => count($removedVariables)]),
        ]);

        return to_route('projects.show', ['current_team' => $currentTeam->slug, 'project' => $project->slug]);
    }
}
