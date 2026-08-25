<?php

namespace App\Http\Controllers\Environments;

use App\Actions\Releases\DiffReleases;
use App\Actions\Variables\ResolveEnvironmentVariables;
use App\Data\ResolvedVariable;
use App\Data\VariableChange;
use App\Http\Controllers\Controller;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class EnvironmentController extends Controller
{
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
            'environment' => [
                'name' => $environment->name,
                'slug' => $environment->slug,
                'autoPublish' => $environment->auto_publish,
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
                'version' => $entry->version->version,
                'updatedAt' => $entry->version->created_at?->toISOString(),
            ]),
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
            ],
        ]);
    }
}
