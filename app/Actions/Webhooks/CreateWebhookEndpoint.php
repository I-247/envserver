<?php

namespace App\Actions\Webhooks;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\WebhookKind;
use App\Models\Team;
use App\Models\User;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Str;

class CreateWebhookEndpoint
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    /**
     * Add an endpoint for the team.
     *
     * The signing secret is generated here and never asked for: a receiver
     * has to be told it once, and one the server made is not one somebody
     * reused from another system.
     *
     * @param  list<string>  $events  audit action values, empty for everything
     */
    public function handle(
        Team $team,
        string $name,
        WebhookKind $kind,
        string $url,
        array $events = [],
        ?User $actor = null,
    ): WebhookEndpoint {
        $endpoint = $team->webhookEndpoints()->create([
            'name' => $name,
            'kind' => $kind,
            'url' => $url,
            'signing_secret' => Str::random(64),
            'events' => $events === [] ? null : $events,
            'active' => true,
            'created_by' => $actor?->id,
        ]);

        $this->audit->handle($team, AuditAction::WebhookEndpointCreated, $actor, $endpoint, [
            'name' => $name,
            'kind' => $kind->value,
            // The host, not the URL: a Slack webhook URL is itself the
            // credential, and the trail is read by more people than may
            // post into that channel.
            'host' => parse_url($url, PHP_URL_HOST),
            'events' => $events === [] ? 'all' : count($events),
        ]);

        return $endpoint;
    }
}
