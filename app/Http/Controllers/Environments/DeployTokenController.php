<?php

namespace App\Http\Controllers\Environments;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\DeployTokens\CreateDeployToken;
use App\Enums\ApiScope;
use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Models\DeployToken;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class DeployTokenController extends Controller
{
    /**
     * List the environment's deploy tokens.
     */
    public function index(Request $request, Team $currentTeam, Project $project, Environment $environment): Response
    {
        Gate::authorize('manageDeployTokens', $project);

        return Inertia::render('environments/deploy-tokens', [
            'project' => ['name' => $project->name, 'slug' => $project->slug],
            'environment' => ['name' => $environment->name, 'slug' => $environment->slug],
            'server' => config('app.url'),
            'tokens' => $environment->deployTokens()
                ->latest()
                ->get()
                ->map(fn (DeployToken $token) => [
                    'id' => $token->id,
                    'name' => $token->name,
                    'clientId' => $token->oauth_client_id,
                    'scopes' => $token->scopes,
                    'lastUsedAt' => $token->last_used_at?->toISOString(),
                    'revokedAt' => $token->revoked_at?->toISOString(),
                    'createdAt' => $token->created_at?->toISOString(),
                ]),
            // Only ever populated by the redirect straight after creating one.
            'newToken' => $request->session()->get('newToken'),
        ]);
    }

    /**
     * Issue a deploy token for this environment.
     */
    public function store(
        Request $request,
        Team $currentTeam,
        Project $project,
        Environment $environment,
        CreateDeployToken $create,
    ): RedirectResponse {
        Gate::authorize('manageDeployTokens', $project);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        // Read only, deliberately not configurable from this screen. A deploy
        // server needs to fetch a release and nothing else; write access
        // belongs to a person or to CI.
        $token = $create->handle(
            $environment,
            $validated['name'],
            $request->user(),
            [ApiScope::EnvironmentRead->value],
        );

        // Explicit destination rather than back(): this redirect is the one
        // and only render that may show the secret, so it must not depend on
        // a Referer header being present.
        return to_route('environments.deploy-tokens.index', [
            'current_team' => $currentTeam->slug,
            'project' => $project->slug,
            'environment' => $environment->slug,
        ])->with('newToken', [
            'name' => $token->model->name,
            'clientId' => $token->clientId,
            'clientSecret' => $token->clientSecret,
        ]);
    }

    /**
     * Revoke a deploy token.
     */
    public function destroy(
        Request $request,
        Team $currentTeam,
        Project $project,
        Environment $environment,
        DeployToken $deployToken,
        RecordAuditEvent $audit,
    ): RedirectResponse {
        Gate::authorize('manageDeployTokens', $project);

        $deployToken->revoke();

        $audit->handle($currentTeam, AuditAction::DeployTokenRevoked, $request->user(), $deployToken, [
            'name' => $deployToken->name,
            'project' => $project->slug,
            'environment' => $environment->slug,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Deploy token revoked.')]);

        return back();
    }
}
