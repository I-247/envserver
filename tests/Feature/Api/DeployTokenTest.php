<?php

use App\Actions\DeployTokens\CreateDeployToken;
use App\Actions\Releases\PublishRelease;
use App\Actions\Variables\AttachVariableToEnvironment;
use App\Actions\Variables\CreateVariable;
use App\Actions\Variables\UpdateVariableValue;
use App\Data\NewDeployToken;
use App\Enums\AuditAction;
use App\Models\AuditEvent;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->project = Project::factory()->for($this->team)->create();
    $this->environment = Environment::factory()->for($this->project)->create([
        'slug' => 'production',
        'auto_publish' => false,
    ]);
    $this->user = User::factory()->create();
});

function seedVariable(string $key, string $value, ?Environment $environment = null): void
{
    $variable = app(CreateVariable::class)->handle(test()->team, $key, $value);

    app(AttachVariableToEnvironment::class)->handle($variable, $environment ?? test()->environment);
}

function issueDeployToken(?Environment $environment = null, array $scopes = ['env:read']): NewDeployToken
{
    return app(CreateDeployToken::class)->handle(
        $environment ?? test()->environment,
        'Ploi production',
        test()->user,
        $scopes,
    );
}

function accessTokenFor(NewDeployToken $token, string $scope = 'env:read'): ?string
{
    return test()->postJson('/oauth/token', [
        'grant_type' => 'client_credentials',
        'client_id' => $token->clientId,
        'client_secret' => $token->clientSecret,
        'scope' => $scope,
    ])->json('access_token');
}

it('hands out a client id and secret exactly once', function () {
    $token = issueDeployToken();

    expect($token->clientId)->not->toBeEmpty()
        ->and($token->clientSecret)->not->toBeEmpty()
        ->and($token->model->environment_id)->toBe($this->environment->id)
        ->and($token->model->name)->toBe('Ploi production')
        ->and($token->model->toArray())->not->toHaveKey('client_secret');
});

it('exchanges client credentials for an access token', function () {
    expect(accessTokenFor(issueDeployToken()))->not->toBeNull();
});

it('serves the latest release of its own environment', function () {
    seedVariable('APP_ENV', 'production');
    seedVariable('DB_PASSWORD', 'hunter2');
    app(PublishRelease::class)->handle($this->environment, $this->user, 'eerste');

    $access = accessTokenFor(issueDeployToken());

    $this->withToken($access)
        ->getJson('/api/v1/deploy/release')
        ->assertOk()
        ->assertJsonPath('data.version', 1)
        ->assertJsonPath('data.environment', 'production')
        ->assertJsonPath('data.variables.APP_ENV', 'production')
        ->assertJsonPath('data.variables.DB_PASSWORD', 'hunter2');
});

it('serves a rendered .env file', function () {
    seedVariable('DB_PASSWORD', 'p$ss word');
    app(PublishRelease::class)->handle($this->environment, $this->user);

    $response = $this->withToken(accessTokenFor(issueDeployToken()))
        ->get('/api/v1/deploy/env')
        ->assertOk();

    expect(Dotenv\Dotenv::parse($response->getContent()))
        ->toBe(['DB_PASSWORD' => 'p$ss word']);
});

it('serves an older release when asked for one', function () {
    $variable = app(CreateVariable::class)->handle($this->team, 'A', 'old');
    app(AttachVariableToEnvironment::class)->handle($variable, $this->environment);
    app(PublishRelease::class)->handle($this->environment, $this->user);

    app(UpdateVariableValue::class)->handle($variable, 'new');
    app(PublishRelease::class)->handle($this->environment->fresh(), $this->user);

    $access = accessTokenFor(issueDeployToken());

    $this->withToken($access)->getJson('/api/v1/deploy/release?version=1')
        ->assertOk()
        ->assertJsonPath('data.variables.A', 'old');

    $this->withToken($access)->getJson('/api/v1/deploy/release')
        ->assertOk()
        ->assertJsonPath('data.variables.A', 'new');
});

it('reports when an environment has no release yet', function () {
    $this->withToken(accessTokenFor(issueDeployToken()))
        ->getJson('/api/v1/deploy/release')
        ->assertNotFound();
});

it('cannot reach another environment', function () {
    $other = Environment::factory()->for($this->project)->create(['slug' => 'staging']);
    seedVariable('SECRET', 'staging-only', $other);
    app(PublishRelease::class)->handle($other, $this->user);

    seedVariable('SECRET', 'production-only');
    app(PublishRelease::class)->handle($this->environment, $this->user);

    $this->withToken(accessTokenFor(issueDeployToken()))
        ->getJson('/api/v1/deploy/release')
        ->assertOk()
        ->assertJsonPath('data.variables.SECRET', 'production-only');
});

it('refuses a token that was not granted the read scope', function () {
    $token = issueDeployToken(scopes: ['env:write']);

    $this->withToken(accessTokenFor($token, 'env:write'))
        ->getJson('/api/v1/deploy/release')
        ->assertForbidden();
});

it('refuses a revoked token', function () {
    seedVariable('A', '1');
    app(PublishRelease::class)->handle($this->environment, $this->user);

    $token = issueDeployToken();
    $access = accessTokenFor($token);

    $token->model->revoke();

    $this->withToken($access)->getJson('/api/v1/deploy/release')->assertForbidden();
});

it('refuses an expired token', function () {
    seedVariable('A', '1');
    app(PublishRelease::class)->handle($this->environment, $this->user);

    $token = issueDeployToken();
    $access = accessTokenFor($token);

    $token->model->update(['expires_at' => now()->subMinute()]);

    $this->withToken($access)->getJson('/api/v1/deploy/release')->assertForbidden();
});

it('refuses a request without a token', function () {
    $this->getJson('/api/v1/deploy/release')->assertUnauthorized();
});

it('records when the token was last used', function () {
    seedVariable('A', '1');
    app(PublishRelease::class)->handle($this->environment, $this->user);

    $token = issueDeployToken();
    expect($token->model->last_used_at)->toBeNull();

    $this->withToken(accessTokenFor($token))->getJson('/api/v1/deploy/release')->assertOk();

    expect($token->model->fresh()->last_used_at)->not->toBeNull();
});

it('pushes variables with a token granted the write scope', function () {
    $token = issueDeployToken(scopes: ['env:read', 'env:write']);

    $this->withToken(accessTokenFor($token, 'env:write'))
        ->postJson('/api/v1/deploy/variables', ['variables' => ['APP_ENV' => 'production']])
        ->assertOk()
        ->assertJsonPath('data.created', 1);

    expect($this->environment->fresh()->variables()->where('key', 'APP_ENV')->exists())->toBeTrue();
});

it('refuses a push from a token that was not granted the write scope', function () {
    $token = issueDeployToken(scopes: ['env:read']);

    $this->withToken(accessTokenFor($token))
        ->postJson('/api/v1/deploy/variables', ['variables' => ['APP_ENV' => 'production']])
        ->assertForbidden();
});

it('records which deploy token pushed', function () {
    $token = issueDeployToken(scopes: ['env:read', 'env:write']);

    $this->withToken(accessTokenFor($token, 'env:write'))
        ->postJson('/api/v1/deploy/variables', ['variables' => ['APP_ENV' => 'production']])
        ->assertOk();

    $event = AuditEvent::where('action', AuditAction::DeployTokenPushed)->sole();

    expect($event->actor_id)->toBeNull()
        ->and($event->subject_id)->toBe($token->model->id)
        ->and($event->metadata['created'])->toBe(1);
});

it('counts every use of the token', function () {
    seedVariable('A', '1');
    app(PublishRelease::class)->handle($this->environment, $this->user);

    $token = issueDeployToken();
    expect($token->model->use_count)->toBe(0);

    $accessToken = accessTokenFor($token);

    $this->withToken($accessToken)->getJson('/api/v1/deploy/release')->assertOk();
    $this->withToken($accessToken)->getJson('/api/v1/deploy/release')->assertOk();

    expect($token->model->fresh()->use_count)->toBe(2);
});
