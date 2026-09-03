<?php

namespace App\Http\Controllers\Projects;

use App\Actions\Projects\CreateProject;
use App\Actions\Projects\DeleteProject;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\SaveProjectRequest;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    /**
     * Display the team's projects.
     */
    public function index(Request $request, Team $currentTeam): Response
    {
        Gate::authorize('viewAny', [Project::class, $currentTeam]);

        return Inertia::render('projects/index', [
            'projects' => $currentTeam->projects()
                ->with('environments')
                ->withMax('deployTokens', 'last_used_at')
                ->withSum('deployTokens', 'use_count')
                ->orderBy('name')
                ->get()
                ->map(fn (Project $project) => [
                    'name' => $project->name,
                    'slug' => $project->slug,
                    'description' => $project->description,
                    'environments' => $project->environments->map(fn (Environment $environment) => [
                        'name' => $environment->name,
                        'slug' => $environment->slug,
                    ]),
                    // Read through getAttribute: withSum and withMax graft
                    // these on as query results, so they are not columns and
                    // have no property to declare.
                    'deployCount' => (int) $project->getAttribute('deploy_tokens_sum_use_count'),
                    'lastDeployedAt' => $project->getAttribute('deploy_tokens_max_last_used_at')
                        ? Carbon::parse($project->getAttribute('deploy_tokens_max_last_used_at'))->toISOString()
                        : null,
                ]),
            'permissions' => [
                'canCreateProject' => $request->user()->can('create', [Project::class, $currentTeam]),
            ],
        ]);
    }

    /**
     * Store a newly created project.
     */
    public function store(SaveProjectRequest $request, Team $currentTeam, CreateProject $createProject): RedirectResponse
    {
        Gate::authorize('create', [Project::class, $currentTeam]);

        $project = $createProject->handle(
            $currentTeam,
            $request->validated('name'),
            $request->validated('description'),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Project created.')]);

        return to_route('projects.show', ['current_team' => $currentTeam->slug, 'project' => $project->slug]);
    }

    /**
     * Display the project and its environments.
     */
    public function show(Request $request, Team $currentTeam, Project $project): Response
    {
        Gate::authorize('view', $project);

        return Inertia::render('projects/show', [
            'project' => [
                'name' => $project->name,
                'slug' => $project->slug,
                'description' => $project->description,
                'environments' => $project->environments()
                    ->withCount('variables')
                    ->get()
                    ->map(fn (Environment $environment) => [
                        'name' => $environment->name,
                        'slug' => $environment->slug,
                        'autoPublish' => $environment->auto_publish,
                        'variableCount' => (int) $environment->variables_count,
                    ]),
            ],
            'permissions' => [
                'canUpdateProject' => $request->user()->can('update', $project),
                'canDeleteProject' => $request->user()->can('delete', $project),
            ],
        ]);
    }

    /**
     * Update the given project.
     */
    public function update(SaveProjectRequest $request, Team $currentTeam, Project $project): RedirectResponse
    {
        Gate::authorize('update', $project);

        $project->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Project updated.')]);

        return to_route('projects.show', ['current_team' => $currentTeam->slug, 'project' => $project->slug]);
    }

    /**
     * Delete the given project along with the variables it leaves behind.
     */
    public function destroy(Request $request, Team $currentTeam, Project $project, DeleteProject $deleteProject): RedirectResponse
    {
        Gate::authorize('delete', $project);

        $removedVariables = $deleteProject->handle($project, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => $removedVariables === []
            ? __('Project deleted.')
            : trans_choice('Project deleted, along with :count unused variable.|Project deleted, along with :count unused variables.', count($removedVariables), ['count' => count($removedVariables)]),
        ]);

        return to_route('projects.index', ['current_team' => $currentTeam->slug]);
    }
}
