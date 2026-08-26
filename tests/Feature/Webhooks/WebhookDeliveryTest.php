<?php

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Webhooks\CreateWebhookEndpoint;
use App\Enums\AuditAction;
use App\Enums\TeamRole;
use App\Enums\WebhookKind;
use App\Jobs\DeliverWebhook;
use App\Models\Team;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    // Adding an endpoint is itself an audited action, so the creation event
    // is delivered to the endpoint that was just created. That is deliberate
    // — it is the cheapest possible "does this URL work" — but it means no
    // test here may leave a real request to make.
    Http::fake(['*' => Http::response('', 200)]);

    $this->team = Team::factory()->create(['slug' => 'acme']);
});

function endpoint(array $events = [], WebhookKind $kind = WebhookKind::Json): WebhookEndpoint
{
    return app(CreateWebhookEndpoint::class)->handle(
        test()->team,
        'Ops channel',
        $kind,
        'https://hooks.example.com/services/abcdefghijklmnop',
        $events,
    );
}

function record(AuditAction $action = AuditAction::ReleasePublished): void
{
    app(RecordAuditEvent::class)->handle(test()->team, $action, null, null, [
        'project' => 'webshop',
        'environment' => 'production',
    ]);
}

it('queues a delivery for an endpoint that wants everything', function () {
    endpoint();
    Queue::fake();

    record();

    Queue::assertPushed(DeliverWebhook::class, 1);
});

it('skips an endpoint that asked for other events', function () {
    endpoint(events: [AuditAction::SecretRevealed->value]);
    Queue::fake();

    record(AuditAction::ReleasePublished);

    Queue::assertNotPushed(DeliverWebhook::class);

    record(AuditAction::SecretRevealed);

    Queue::assertPushed(DeliverWebhook::class, 1);
});

it('skips an endpoint that was switched off', function () {
    endpoint()->forceFill(['active' => false])->save();
    Queue::fake();

    record();

    Queue::assertNotPushed(DeliverWebhook::class);
});

it('never sends to another team', function () {
    endpoint();
    Queue::fake();

    $other = Team::factory()->create();
    app(RecordAuditEvent::class)->handle($other, AuditAction::ReleasePublished);

    Queue::assertNotPushed(DeliverWebhook::class);
});

it('signs a json delivery over the exact body it sends', function () {
    $endpoint = endpoint();
    app(DeliverWebhook::class, ['endpoint' => $endpoint, 'payload' => [
        'id' => 7,
        'action' => AuditAction::ReleasePublished->value,
        'label' => 'Release published',
        'metadata' => [],
    ]])->handle();

    Http::assertSent(function ($request) use ($endpoint) {
        $signature = $request->header('X-Kluis-Signature')[0] ?? '';

        return $signature === 'sha256='.$endpoint->sign($request->body())
            && ($request->header('X-Kluis-Event')[0] ?? '') === 'release.published'
            && ($request->header('X-Kluis-Delivery')[0] ?? '') === '7';
    });
});

it('sends slack a single text field and no signature', function () {
    $endpoint = endpoint(kind: WebhookKind::Slack);
    app(DeliverWebhook::class, ['endpoint' => $endpoint, 'payload' => [
        'id' => 7,
        'action' => AuditAction::ReleasePublished->value,
        'label' => 'Release published',
        'actor' => 'Sebastiaan',
        'metadata' => ['project' => 'webshop', 'environment' => 'production'],
    ]])->handle();

    Http::assertSent(function ($request) {
        $body = json_decode($request->body(), true);

        return array_keys($body) === ['text']
            && str_contains($body['text'], 'Release published')
            && str_contains($body['text'], 'Sebastiaan')
            && str_contains($body['text'], 'webshop/production')
            && $request->header('X-Kluis-Signature') === [];
    });
});

it('records a delivery that arrived', function () {
    $endpoint = endpoint();
    $endpoint->forceFill([
        'consecutive_failures' => 3,
        'last_status' => 500,
        'last_error' => 'The endpoint answered 500.',
    ])->save();

    app(DeliverWebhook::class, ['endpoint' => $endpoint, 'payload' => ['id' => 1, 'action' => 'x', 'metadata' => []]])->handle();

    $endpoint->refresh();

    expect($endpoint->last_status)->toBe(200)
        ->and($endpoint->consecutive_failures)->toBe(0)
        ->and($endpoint->last_error)->toBeNull();
});

it('retires an endpoint that has failed too often', function () {
    $endpoint = endpoint();
    $endpoint->forceFill(['consecutive_failures' => WebhookEndpoint::FAILURE_LIMIT - 1])->save();

    $endpoint->recordFailure(500, 'The endpoint answered 500.');

    expect($endpoint->fresh()->active)->toBeFalse();
});

it('does not count a retry that will be tried again', function () {
    $endpoint = endpoint();

    $endpoint->recordFailure(500, 'nope', final: false);

    expect($endpoint->fresh()->consecutive_failures)->toBe(0)
        ->and($endpoint->fresh()->last_status)->toBe(500)
        ->and($endpoint->fresh()->active)->toBeTrue();
});

it('never puts a secret value in the payload', function () {
    endpoint();

    app(RecordAuditEvent::class)->handle($this->team, AuditAction::SecretRevealed, null, null, [
        'key' => 'DB_PASSWORD',
        'project' => 'webshop',
    ]);

    Http::assertSent(fn ($request) => str_contains($request->body(), 'DB_PASSWORD')
        && ! str_contains($request->body(), 'signing_secret'));
});

it('keeps the signing secret out of the settings page', function () {
    actingAsTeamMember(TeamRole::Owner, $this->team);

    $endpoint = endpoint();

    $response = $this->get('/settings/teams/acme')->assertOk();

    expect($response->getContent())
        ->not->toContain($endpoint->signing_secret)
        ->and($response->getContent())->not->toContain('abcdefghijklmnop');
});
