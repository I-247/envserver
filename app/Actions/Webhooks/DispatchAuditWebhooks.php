<?php

namespace App\Actions\Webhooks;

use App\Jobs\DeliverWebhook;
use App\Models\AuditEvent;
use App\Models\WebhookEndpoint;

/**
 * Fans one audit event out to the team's endpoints.
 *
 * Hung off RecordAuditEvent rather than off each action that might be worth
 * announcing: the trail is already the one place that knows everything worth
 * knowing, and a second list of "events we also send" would drift from it
 * the first time somebody adds an action.
 */
class DispatchAuditWebhooks
{
    /**
     * Queue a delivery for every endpoint that asked for this event.
     */
    public function handle(AuditEvent $event): void
    {
        $endpoints = WebhookEndpoint::query()
            ->active()
            ->where('team_id', $event->team_id)
            ->get()
            ->filter(fn (WebhookEndpoint $endpoint) => $endpoint->wants($event->action));

        if ($endpoints->isEmpty()) {
            return;
        }

        $payload = $this->payload($event);

        foreach ($endpoints as $endpoint) {
            DeliverWebhook::dispatch($endpoint, $payload);
        }
    }

    /**
     * Describe the event for a receiver.
     *
     * Carries exactly what the trail carries and nothing more. The audit
     * metadata is names, counts and slugs by construction, so this can be
     * passed on whole: there is no value in it to leak.
     *
     * @return array<string, mixed>
     */
    private function payload(AuditEvent $event): array
    {
        return [
            'id' => $event->id,
            'action' => $event->action->value,
            'label' => $event->action->label(),
            'team' => [
                'name' => $event->team->name,
                'slug' => $event->team->slug,
            ],
            'actor' => $event->actor_name,
            'subject' => $event->subject_type === null ? null : [
                'type' => class_basename($event->subject_type),
                'id' => $event->subject_id,
            ],
            'metadata' => $event->metadata ?? [],
            'ip' => $event->ip_address,
            'occurredAt' => $event->created_at?->toISOString(),
        ];
    }
}
