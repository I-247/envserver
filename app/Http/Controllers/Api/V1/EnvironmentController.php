<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Releases\DiffReleases;
use App\Actions\Releases\PublishRelease;
use App\Actions\Variables\PushVariables;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\PushVariablesRequest;
use App\Http\Resources\ReleaseResource;
use App\Http\Resources\ReleaseSummaryResource;
use App\Http\Resources\VariableChangeResource;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Release;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * The endpoints a signed in developer's CLI talks to.
 *
 * Unlike the deploy endpoints these name the environment in the path, because
 * a personal token is not bound to one.
 */
class EnvironmentController extends Controller
{
    /**
     * Get a release, defaulting to the latest one.
     */
    public function release(Request $request, Team $team, Project $project, Environment $environment): ReleaseResource
    {
        Gate::authorize('view', $project);

        return new ReleaseResource($this->resolveRelease($request, $environment));
    }

    /**
     * Get a release rendered as a .env file.
     */
    public function env(Request $request, Team $team, Project $project, Environment $environment): Response
    {
        Gate::authorize('view', $project);

        return response($this->resolveRelease($request, $environment)->toEnvFile(), 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * List the environment's release history.
     */
    public function releases(Team $team, Project $project, Environment $environment): AnonymousResourceCollection
    {
        Gate::authorize('view', $project);

        return ReleaseSummaryResource::collection(
            $environment->releases()->with('publisher')->paginate(50)
        );
    }

    /**
     * Show what would change if the environment were published right now.
     */
    public function pending(
        Team $team,
        Project $project,
        Environment $environment,
        DiffReleases $diff,
    ): AnonymousResourceCollection {
        Gate::authorize('view', $project);

        return VariableChangeResource::collection(
            $diff->pending($environment, reveal: Gate::allows('viewSecrets', $project))
        );
    }

    /**
     * Push a set of variables into the environment.
     */
    public function push(
        PushVariablesRequest $request,
        Team $team,
        Project $project,
        Environment $environment,
        PushVariables $push,
    ): JsonResponse {
        Gate::authorize('manageVariables', $project);

        return response()->json([
            'data' => $push->handle(
                $environment,
                $request->validated('variables'),
                $request->user(),
            ),
        ]);
    }

    /**
     * Publish a release for the environment.
     */
    public function publish(
        Request $request,
        Team $team,
        Project $project,
        Environment $environment,
        PublishRelease $publish,
    ): JsonResponse {
        Gate::authorize('publishReleases', $project);

        $release = $publish->handle($environment, $request->user(), $request->string('message')->value() ?: null);

        return (new ReleaseResource($release))->response()->setStatusCode(201);
    }

    /**
     * Resolve the requested release, or the latest one.
     */
    private function resolveRelease(Request $request, Environment $environment): Release
    {
        $release = $request->filled('version')
            ? $environment->releases()->where('version', $request->integer('version'))->first()
            : $environment->latestRelease();

        abort_if($release === null, 404, 'This environment has no published release yet.');

        return $release;
    }
}
