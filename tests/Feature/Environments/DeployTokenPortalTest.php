<?php

use App\Actions\DeployTokens\CreateDeployToken;
use App\Enums\TeamRole;
use App\Models\DeployToken;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Team;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Passport\Client;

beforeEach(function () {
    $this->team = Team::factory()->create(['slug' => 'acme']);
    $this->project = Project::factory()->for($this->team)->create(['slug' => 'webshop']);
    $this->environment = Environment::factory()->for($this->project)->create(['slug' => 'production']);
});

function tokensUrl(string $suffix = ''): string
{
    return "/acme/projects/webshop/environments/production/deploy-tokens{$suffix}";
}

it('lists the environment\'s deploy tokens without any secret', function () {
    actingAsTeamMember(TeamRole::Admin, $this->team);
    app(CreateDeployToken::class)->handle($this->environment, 'Ploi production');

    $response = $this->get(tokensUrl())->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->component('environments/deploy-tokens')
        ->has('tokens', 1)
        ->where('tokens.0.name', 'Ploi production')
        ->where('tokens.0.lastUsedAt', null)
        ->where('tokens.0.useCount', 0)
    );

    expect($response->getContent())->not->toContain('client_secret');
});

it('reports how often a token has been used', function () {
    actingAsTeamMember(TeamRole::Admin, $this->team);
    $token = app(CreateDeployToken::class)->handle($this->environment, 'Ploi production');

    $token->model->markUsed();
    $token->model->markUsed();

    $this->get(tokensUrl())->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('environments/deploy-tokens')
        ->where('tokens.0.useCount', 2)
    );
});

it('shows the secret exactly once, right after creating the token', function () {
    actingAsTeamMember(TeamRole::Admin, $this->team);

    // Following the redirect is the page that shows the secret, exactly as a
    // browser would. The flash lives for that one request and no longer.
    $this->followingRedirects()
        ->post(tokensUrl(), ['name' => 'Ploi production'])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('newToken.name', 'Ploi production')
            ->has('newToken.clientSecret')
        );

    $token = DeployToken::sole();

    expect($token->environment_id)->toBe($this->environment->id)
        ->and($token->scopes)->toBe(['env:read']);

    // Reloading the page must not show it again.
    $this->get(tokensUrl())
        ->assertInertia(fn (Assert $page) => $page->where('newToken', null));
});

it('hands the page everything the downloaded file needs', function () {
    actingAsTeamMember(TeamRole::Admin, $this->team);
    config(['app.url' => 'https://envserver.example.com']);

    // The file is built in the browser from exactly these three props, because
    // the secret is stored hashed and the server could never serve it again.
    $this->followingRedirects()
        ->post(tokensUrl(), ['name' => 'Ploi production'])
        ->assertInertia(fn (Assert $page) => $page
            ->where('server', 'https://envserver.example.com')
            ->where('project.slug', 'webshop')
            ->where('environment.slug', 'production')
            ->has('newToken.clientId')
            ->has('newToken.clientSecret')
        );
});

it('stores the client secret hashed, so it can never be served again', function () {
    actingAsTeamMember(TeamRole::Admin, $this->team);
    $token = app(CreateDeployToken::class)->handle($this->environment, 'Ploi');

    $stored = Client::findOrFail($token->clientId)->getRawOriginal('secret');

    expect($stored)->not->toBe($token->clientSecret)
        ->and($stored)->toStartWith('$2y$');
});

it('gives a deploy token read access only by default', function () {
    actingAsTeamMember(TeamRole::Admin, $this->team);

    // A client-supplied scopes array is never trusted directly — only the
    // can_push checkbox below may add env:write.
    $this->post(tokensUrl(), ['name' => 'Ploi', 'scopes' => ['env:read', 'env:write']])
        ->assertRedirect();

    expect(DeployToken::sole()->scopes)->toBe(['env:read']);
});

it('grants push access when can_push is checked', function () {
    actingAsTeamMember(TeamRole::Admin, $this->team);

    $this->post(tokensUrl(), ['name' => 'CI', 'can_push' => true])
        ->assertRedirect();

    expect(DeployToken::sole()->scopes)->toBe(['env:read', 'env:write']);
});

// A native checked checkbox submits "on", not true/"1" — the actual value a
// browser sends, and the exact value that silently failed a strict "boolean"
// validation rule before.
it('grants push access from a real browser checkbox value', function () {
    actingAsTeamMember(TeamRole::Admin, $this->team);

    $this->post(tokensUrl(), ['name' => 'CI', 'can_push' => 'on'])
        ->assertRedirect(tokensUrl());

    expect(DeployToken::sole()->scopes)->toBe(['env:read', 'env:write']);
});

it('leaves push access off when can_push is not sent at all', function () {
    actingAsTeamMember(TeamRole::Admin, $this->team);

    $this->post(tokensUrl(), ['name' => 'Ploi'])
        ->assertRedirect(tokensUrl());

    expect(DeployToken::sole()->scopes)->toBe(['env:read']);
});

it('revokes a token', function () {
    actingAsTeamMember(TeamRole::Admin, $this->team);
    $token = app(CreateDeployToken::class)->handle($this->environment, 'Ploi')->model;

    $this->delete(tokensUrl("/{$token->id}"))->assertRedirect();

    expect($token->fresh()->isUsable())->toBeFalse();
});

it('forbids a member from managing deploy tokens', function () {
    actingAsTeamMember(TeamRole::Member, $this->team);

    $this->post(tokensUrl(), ['name' => 'Ploi'])->assertForbidden();
    expect(DeployToken::count())->toBe(0);
});

it('does not revoke a token belonging to another environment', function () {
    actingAsTeamMember(TeamRole::Admin, $this->team);

    $other = Environment::factory()->for(Project::factory()->for($this->team))->create();
    $token = app(CreateDeployToken::class)->handle($other, 'Elsewhere')->model;

    $this->delete(tokensUrl("/{$token->id}"))->assertNotFound();

    expect($token->fresh()->isUsable())->toBeTrue();
});
