<?php

namespace App\Actions\Webhooks;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\User;
use App\Models\WebhookEndpoint;

class DeleteWebhookEndpoint
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    /**
     * Remove an endpoint.
     *
     * Recorded before the delete so the subject still exists to point at,
     * and worth recording at all because removing the listener is how you
     * would go quiet before doing something else.
     */
    public function handle(WebhookEndpoint $endpoint, ?User $actor = null): void
    {
        $this->audit->handle($endpoint->team, AuditAction::WebhookEndpointDeleted, $actor, $endpoint, [
            'name' => $endpoint->name,
            'kind' => $endpoint->kind->value,
            'host' => parse_url($endpoint->url, PHP_URL_HOST),
        ]);

        $endpoint->delete();
    }
}
