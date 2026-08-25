<?php

namespace App\Http\Controllers\Environments;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Releases\PublishRelease;
use App\Actions\Releases\RollbackToRelease;
use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Release;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ReleaseController extends Controller
{
    /**
     * List the environment's release history.
     */
    public function index(
        Request $request,
        Team $currentTeam,
        Project $project,
        Environment $environment,
    ): Response {
        Gate::authorize('view', $project);

        return Inertia::render('environments/releases', [
            'project' => ['name' => $project->name, 'slug' => $project->slug],
            'environment' => ['name' => $environment->name, 'slug' => $environment->slug],
            'releases' => $environment->releases()
                ->with('publisher')
                ->withCount('items')
                ->get()
                ->map(fn (Release $release) => [
                    'id' => $release->id,
                    'version' => $release->version,
                    'message' => $release->message,
                    'publishedBy' => $release->publisher?->name,
                    'publishedAt' => $release->created_at?->toISOString(),
                    'variablesCount' => $release->items_count,
                ]),
            'permissions' => [
                'canPublishRelease' => $request->user()->can('publishReleases', $project),
            ],
        ]);
    }

    /**
     * Publish the environment's current state as a new release.
     */
    public function store(
        Request $request,
        Team $currentTeam,
        Project $project,
        Environment $environment,
        PublishRelease $publish,
    ): RedirectResponse {
        Gate::authorize('publishReleases', $project);

        $release = $publish->handle(
            $environment,
            $request->user(),
            $request->string('message')->value() ?: null,
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Release :version published.', ['version' => $release?->version]),
        ]);

        return back();
    }

    /**
     * Roll the environment back to an earlier release.
     */
    public function rollback(
        Request $request,
        Team $currentTeam,
        Project $project,
        Environment $environment,
        Release $release,
        RollbackToRelease $rollback,
        RecordAuditEvent $audit,
    ): RedirectResponse {
        Gate::authorize('publishReleases', $project);

        $impact = $rollback->sharedImpact($release);

        $restored = $rollback->handle($release, $request->user());

        $audit->handle($currentTeam, AuditAction::ReleaseRolledBack, $request->user(), $release, [
            'project' => $project->slug,
            'environment' => $environment->slug,
            'to' => $release->version,
            'restored_as' => $restored?->version,
            'other_environments_affected' => $impact->count(),
        ]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $impact->isEmpty()
                ? __('Rolled back to release :version.', ['version' => $release->version])
                : __('Rolled back to release :version. :count other environment(s) changed too, because the variables are shared.', [
                    'version' => $release->version,
                    'count' => $impact->count(),
                ]),
        ]);

        return to_route('environments.releases.index', [
            'current_team' => $currentTeam->slug,
            'project' => $project->slug,
            'environment' => $environment->slug,
        ])->with('restored', $restored?->version);
    }
}
