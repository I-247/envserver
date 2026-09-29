<?php

use App\Actions\Webhooks\CreateWebhookEndpoint;
use App\Enums\AuditAction;
use App\Enums\TeamRole;
use App\Enums\WebhookKind;
use App\Models\AuditEvent;
use App\Models\Team;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Http::fake(['*' => Http::response('', 200)]);
    fakeDns();

    $this->team = Team::factory()->create(['slug' => 'acme']);
});

it('adds an endpoint and records who did it', function () {
    $actor = actingAsTeamMember(TeamRole::Owner, $this->team);

    $this->post('/settings/teams/acme/webhooks', [
        'name' => 'Ops channel',
        'kind' => WebhookKind::Slack->value,
        'url' => 'https://hooks.slack.com/services/T000/B000/xyz',
        'events' => [AuditAction::ReleasePublished->value],
    ])->assertRedirect('/settings/teams/acme');

    $endpoint = WebhookEndpoint::sole();

    expect($endpoint->name)->toBe('Ops channel')
        ->and($endpoint->kind)->toBe(WebhookKind::Slack)
        ->and($endpoint->events)->toBe([AuditAction::ReleasePublished->value])
        ->and($endpoint->signing_secret)->not->toBeEmpty();

    $event = AuditEvent::query()->where('action', AuditAction::WebhookEndpointCreated->value)->sole();

    expect($event->actor_id)->toBe($actor->id)
        ->and($event->metadata['host'])->toBe('hooks.slack.com')
        // The trail records where it points, never the URL itself: a Slack
        // webhook URL is the credential to post into that channel.
        ->and($event->metadata)->not->toHaveKey('url');
});

it('treats no events as every event', function () {
    actingAsTeamMember(TeamRole::Owner, $this->team);

    $this->post('/settings/teams/acme/webhooks', [
        'name' => 'Everything',
        'kind' => WebhookKind::Json->value,
        'url' => 'https://hooks.example.com/all',
    ])->assertRedirect();

    $endpoint = WebhookEndpoint::sole();

    expect($endpoint->events)->toBeNull()
        ->and($endpoint->wants(AuditAction::SecretRevealed))->toBeTrue()
        ->and($endpoint->wants(AuditAction::ReleasePublished))->toBeTrue();
});

it('refuses a plain http address', function () {
    actingAsTeamMember(TeamRole::Owner, $this->team);

    $this->post('/settings/teams/acme/webhooks', [
        'name' => 'Insecure',
        'kind' => WebhookKind::Json->value,
        'url' => 'http://hooks.example.com/all',
    ])->assertSessionHasErrors('url');

    expect(WebhookEndpoint::count())->toBe(0);
});

it('refuses an address on the server\'s own network', function (string $url) {
    fakeDns([
        'localhost' => ['127.0.0.1'],
        'localhost.' => ['127.0.0.1'],
        '2130706433' => ['127.0.0.1'],
        '127.1' => ['127.0.0.1'],
        'inside.example.com' => ['10.0.0.5'],
        'mixed.example.com' => ['93.184.215.14', '169.254.169.254'],
    ]);

    actingAsTeamMember(TeamRole::Owner, $this->team);

    $this->post('/settings/teams/acme/webhooks', [
        'name' => 'Inside',
        'kind' => WebhookKind::Json->value,
        'url' => $url,
    ])->assertSessionHasErrors('url');
})->with([
    'https://localhost/hook',
    'https://127.0.0.1/hook',
    'https://169.254.169.254/latest/meta-data',
    'https://10.0.0.5/hook',
    'https://192.168.1.10/hook',
    'short loopback' => 'https://127.1/hook',
    'decimal loopback' => 'https://2130706433/hook',
    'private 172.16/12' => 'https://172.16.0.5/hook',
    'carrier-grade NAT' => 'https://100.64.0.1/hook',
    'IPv6 loopback' => 'https://[::1]/hook',
    'IPv4-mapped IPv6' => 'https://[::ffff:127.0.0.1]/hook',
    'IPv6 unique local' => 'https://[fd00:ec2::254]/hook',
    'NAT64' => 'https://[64:ff9b::a00:5]/hook',
    'name pointing inside' => 'https://inside.example.com/hook',
    'name with one inside record' => 'https://mixed.example.com/hook',
    'name that does not resolve' => 'https://nowhere.example.com/hook',
    'trailing dot' => 'https://localhost./hook',
]);

it('refuses an event that is not in the trail', function () {
    actingAsTeamMember(TeamRole::Owner, $this->team);

    $this->post('/settings/teams/acme/webhooks', [
        'name' => 'Nonsense',
        'kind' => WebhookKind::Json->value,
        'url' => 'https://hooks.example.com/all',
        'events' => ['something.invented'],
    ])->assertSessionHasErrors('events.0');
});

it('removes an endpoint and records that too', function () {
    actingAsTeamMember(TeamRole::Owner, $this->team);

    $endpoint = app(CreateWebhookEndpoint::class)->handle(
        $this->team,
        'Ops',
        WebhookKind::Json,
        'https://hooks.example.com/all',
    );

    $this->delete("/settings/teams/acme/webhooks/{$endpoint->id}")->assertRedirect('/settings/teams/acme');

    expect(WebhookEndpoint::count())->toBe(0)
        ->and(AuditEvent::query()->where('action', AuditAction::WebhookEndpointDeleted->value)->count())->toBe(1);
});

it('never removes an endpoint belonging to another team', function () {
    actingAsTeamMember(TeamRole::Owner, $this->team);

    $other = Team::factory()->create();
    $endpoint = app(CreateWebhookEndpoint::class)->handle(
        $other,
        'Theirs',
        WebhookKind::Json,
        'https://hooks.example.com/all',
    );

    $this->delete("/settings/teams/acme/webhooks/{$endpoint->id}")->assertNotFound();

    expect(WebhookEndpoint::count())->toBe(1);
});

it('refuses a member who may not change team settings', function () {
    actingAsTeamMember(TeamRole::Member, $this->team);

    $this->post('/settings/teams/acme/webhooks', [
        'name' => 'Ops',
        'kind' => WebhookKind::Json->value,
        'url' => 'https://hooks.example.com/all',
    ])->assertForbidden();
});

it('shows the endpoints on the team settings page with the url masked', function () {
    actingAsTeamMember(TeamRole::Owner, $this->team);

    app(CreateWebhookEndpoint::class)->handle(
        $this->team,
        'Ops',
        WebhookKind::Slack,
        'https://hooks.slack.com/services/T000/B000/supersecretpart',
    );

    $this->get('/settings/teams/acme')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('webhooks', 1)
            ->where('webhooks.0.name', 'Ops')
            ->where('webhooks.0.url', 'https://hooks.slack.com/services/T0…')
            ->where('webhooks.0.active', true)
            ->has('webhookKinds', 2)
            ->has('webhookEvents')
        );
});

it('stores the url encrypted and never serializes it', function () {
    $endpoint = app(CreateWebhookEndpoint::class)->handle(
        $this->team,
        'Slack',
        WebhookKind::Slack,
        'https://hooks.slack.com/services/T000/B000/secret-part',
    );

    $stored = DB::table('webhook_endpoints')->where('id', $endpoint->id)->value('url');

    expect($stored)->not->toContain('secret-part')
        ->and($endpoint->fresh()->url)->toBe('https://hooks.slack.com/services/T000/B000/secret-part')
        ->and($endpoint->toArray())->not->toHaveKey('url');
});
