<?php

namespace App\Http\Controllers\Teams;

use App\Actions\Webhooks\CreateWebhookEndpoint;
use App\Actions\Webhooks\DeleteWebhookEndpoint;
use App\Enums\WebhookKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Teams\SaveWebhookEndpointRequest;
use App\Models\Team;
use App\Models\WebhookEndpoint;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class WebhookEndpointController extends Controller
{
    /**
     * Add an endpoint to the team.
     */
    public function store(
        SaveWebhookEndpointRequest $request,
        Team $team,
        CreateWebhookEndpoint $createWebhookEndpoint,
    ): RedirectResponse {
        Gate::authorize('update', $team);

        $endpoint = $createWebhookEndpoint->handle(
            $team,
            $request->validated('name'),
            WebhookKind::from($request->validated('kind')),
            $request->validated('url'),
            $request->events(),
            $request->user(),
        );

        // The only time the secret is ever shown. It is encrypted at rest and
        // the model hides it, so a receiver that loses it needs a new
        // endpoint rather than a lookup.
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Endpoint added.')]);

        return to_route('teams.edit', ['team' => $team->slug])
            ->with('webhookSecret', $endpoint->kind->isSigned() ? $endpoint->signing_secret : null);
    }

    /**
     * Remove an endpoint.
     */
    public function destroy(
        Request $request,
        Team $team,
        WebhookEndpoint $webhookEndpoint,
        DeleteWebhookEndpoint $deleteWebhookEndpoint,
    ): RedirectResponse {
        Gate::authorize('update', $team);

        abort_if($webhookEndpoint->team_id !== $team->id, 404);

        $deleteWebhookEndpoint->handle($webhookEndpoint, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Endpoint removed.')]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }
}
