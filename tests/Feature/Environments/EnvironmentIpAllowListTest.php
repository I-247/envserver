<?php

use App\Actions\DeployTokens\CreateDeployToken;
use App\Actions\Releases\PublishRelease;
use App\Actions\Variables\AttachVariableToEnvironment;
use App\Actions\Variables\CreateVariable;
use App\Data\NewDeployToken;
use App\Enums\AuditAction;
use App\Enums\TeamRole;
use App\Models\AuditEvent;
use App\Models\Environment;
use App\Models\Project;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->user = actingAsTeamMember(TeamRole::Owner);
    $this->team = $this->user->currentTeam;
    $this->project = Project::factory()->for($this->team)->create();
    $this->environment = Environment::factory()->for($this->project)->create([
        'slug' => 'production',
        'auto_publish' => true,
    ]);
});

function publishedTokenFor(Environment $environment): NewDeployToken
{
    $variable = app(CreateVariable::class)->handle(test()->team, 'APP_ENV', 'production');
    app(AttachVariableToEnvironment::class)->handle($variable, $environment);
    app(PublishRelease::class)->handle($environment, test()->user);

    return app(CreateDeployToken::class)->handle($environment, 'Ploi production', test()->user, ['env:read']);
}

function pullEnvFile(NewDeployToken $token, string $ip): TestResponse
{
    $accessToken = test()->postJson('/oauth/token', [
        'grant_type' => 'client_credentials',
        'client_id' => $token->clientId,
        'client_secret' => $token->clientSecret,
        'scope' => 'env:read',
    ])->json('access_token');

    return test()->withServerVariables(['REMOTE_ADDR' => $ip])
        ->withToken($accessToken)
        ->get('/api/v1/deploy/env');
}

it('saves an allow list on the environment without demanding the current address', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
        ->patch(route('environments.update', [
            'current_team' => $this->team->slug,
            'project' => $this->project->slug,
            'environment' => $this->environment->slug,
        ]), [
            'name' => $this->environment->name,
            'auto_publish' => '1',
            'ip_allowlist' => "203.0.113.0/24\n192.0.2.10",
        ])
        ->assertSessionHasNoErrors();

    expect($this->environment->fresh()->ip_allowlist)->toBe(['203.0.113.0/24', '192.0.2.10']);
});

it('refuses an entry that is not an address or range', function () {
    $this->patch(route('environments.update', [
        'current_team' => $this->team->slug,
        'project' => $this->project->slug,
        'environment' => $this->environment->slug,
    ]), [
        'name' => $this->environment->name,
        'ip_allowlist' => 'somewhere',
    ])->assertSessionHasErrors('ip_allowlist');
});

it('records the allow list change in the audit trail', function () {
    $this->patch(route('environments.update', [
        'current_team' => $this->team->slug,
        'project' => $this->project->slug,
        'environment' => $this->environment->slug,
    ]), [
        'name' => $this->environment->name,
        'auto_publish' => '1',
        'ip_allowlist' => '203.0.113.0/24',
    ]);

    $event = AuditEvent::where('action', AuditAction::EnvironmentUpdated)->sole();

    expect($event->metadata['from']['ip_allowlist'])->toBe([])
        ->and($event->metadata['to']['ip_allowlist'])->toBe(['203.0.113.0/24']);
});

it('lets a deploy token download from anywhere while the list is empty', function () {
    $token = publishedTokenFor($this->environment);

    pullEnvFile($token, '198.51.100.7')->assertOk();
});

it('blocks a deploy token pulling from outside the list', function () {
    $this->environment->update(['ip_allowlist' => ['203.0.113.0/24']]);

    $token = publishedTokenFor($this->environment);

    pullEnvFile($token, '198.51.100.7')->assertForbidden();
});

it('lets a deploy token pull from inside the list', function () {
    $this->environment->update(['ip_allowlist' => ['203.0.113.0/24']]);

    $token = publishedTokenFor($this->environment);

    pullEnvFile($token, '203.0.113.9')->assertOk()->assertSee('APP_ENV');
});

it('records a blocked pull in the audit trail and does not count it as a use', function () {
    $this->environment->update(['ip_allowlist' => ['203.0.113.0/24']]);

    $token = publishedTokenFor($this->environment);

    pullEnvFile($token, '198.51.100.7')->assertForbidden();

    $event = AuditEvent::where('action', AuditAction::DeployTokenBlocked)->sole();

    expect($event->team_id)->toBe($this->team->id)
        ->and($event->metadata['environment'])->toBe('production')
        ->and($event->ip_address)->toBe('198.51.100.7')
        ->and($token->model->fresh()->use_count)->toBe(0);
});

it('leaves the environment allow list alone for people using the browser', function () {
    $this->environment->update(['ip_allowlist' => ['203.0.113.0/24']]);

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
        ->get(route('environments.show', [
            'current_team' => $this->team->slug,
            'project' => $this->project->slug,
            'environment' => $this->environment->slug,
        ]))
        ->assertOk();
});
