<?php

namespace App\Jobs;

use App\Enums\WebhookKind;
use App\Models\WebhookEndpoint;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Delivers one audit event to one endpoint.
 *
 * Queued rather than sent inline: a slow or unreachable receiver must never
 * be able to hold up the request that changed a variable, and an audit event
 * is written whether or not anyone was listening.
 */
class DeliverWebhook implements ShouldQueue
{
    use Queueable;

    /**
     * How often the delivery is attempted.
     */
    public int $tries = 3;

    /**
     * Seconds to wait between attempts.
     *
     * @var list<int>
     */
    public array $backoff = [10, 60];

    /**
     * @param  array<string, mixed>  $payload  names, counts and slugs, never a value
     */
    public function __construct(
        public readonly WebhookEndpoint $endpoint,
        public readonly array $payload,
    ) {}

    /**
     * Send the event.
     */
    public function handle(): void
    {
        $body = json_encode($this->body(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        try {
            $response = Http::withBody($body, 'application/json')
                ->withHeaders($this->headers($body))
                ->timeout(10)
                ->post($this->endpoint->url);
        } catch (Throwable $exception) {
            $this->endpoint->recordFailure(null, $exception->getMessage(), $this->lastAttempt());

            throw $exception;
        }

        if ($response->failed()) {
            $this->endpoint->recordFailure(
                $response->status(),
                'The endpoint answered '.$response->status().'.',
                $this->lastAttempt(),
            );

            throw new RuntimeException("Webhook delivery to {$this->endpoint->name} failed with status {$response->status()}.");
        }

        $this->endpoint->recordSuccess($response->status());
    }

    /**
     * Determine whether this is the attempt after which nobody tries again.
     */
    private function lastAttempt(): bool
    {
        return $this->attempts() >= $this->tries;
    }

    /**
     * Build the body for this endpoint's kind.
     *
     * @return array<string, mixed>
     */
    private function body(): array
    {
        return $this->endpoint->kind === WebhookKind::Slack
            ? ['text' => $this->slackText()]
            : $this->payload;
    }

    /**
     * Render the event as the one line Slack will show.
     */
    private function slackText(): string
    {
        $parts = array_filter([
            $this->payload['label'] ?? 'Something happened',
            $this->payload['actor'] ?? null,
            $this->subjectLine(),
        ]);

        return '*'.array_shift($parts).'*'.($parts === [] ? '' : ' — '.implode(' · ', $parts));
    }

    /**
     * Describe what the event was about, from the metadata the trail keeps.
     */
    private function subjectLine(): ?string
    {
        $metadata = $this->payload['metadata'] ?? [];

        $described = array_filter([
            $metadata['project'] ?? null,
            $metadata['environment'] ?? null,
            $metadata['key'] ?? null,
        ], fn ($value) => is_string($value));

        return $described === [] ? null : implode('/', $described);
    }

    /**
     * Build the headers, including the signature where the kind has one.
     *
     * @return array<string, string>
     */
    private function headers(string $body): array
    {
        $headers = [
            'User-Agent' => 'Envserver',
            'X-Envserver-Event' => (string) ($this->payload['action'] ?? ''),
            'X-Envserver-Delivery' => (string) ($this->payload['id'] ?? ''),
        ];

        if ($this->endpoint->kind->isSigned()) {
            // Over the exact bytes that are sent, not over a re-encoded copy:
            // a receiver verifies what arrived, and json_encode is not
            // guaranteed to hand back the same string twice.
            $headers['X-Envserver-Signature'] = 'sha256='.$this->endpoint->sign($body);
        }

        return $headers;
    }
}
