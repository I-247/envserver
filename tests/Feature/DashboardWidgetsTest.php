<?php

use App\Actions\DeployTokens\CreateDeployToken;
use App\Actions\Releases\PublishRelease;
use App\Actions\Variables\AttachVariableToEnvironment;
use App\Actions\Variables\CreateVariable;
use App\Enums\TeamRole;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Team;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->team = Team::factory()->create(['slug' => 'acme']);
    $this->project = Project::factory()->for($this->team)->create(['slug' => 'webshop']);
    $this->environment = Environment::factory()->for($this->project)->create([
        'slug' => 'production',
        'auto_publish' => false,
    ]);
});

it('counts what the team owns', function () {
    actingAsTeamMember(TeamRole::Member, $this->team);

    $variable = app(CreateVariable::class)->handle($this->team, 'DB_PASSWORD', 'hunter2');
    app(AttachVariableToEnvironment::class)->handle($variable, $this->environment);
    app(CreateDeployToken::class)->handle($this->environment, 'Ploi production');

    $this->get('/acme/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('stats.projects', 1)
            ->where('stats.environments', 1)
            ->where('stats.variables', 1)
            ->where('stats.deployTokens', 1),
        );
});

it('lists manual environments that drifted from their last release', function () {
    actingAsTeamMember(TeamRole::Member, $this->team);

    $variable = app(CreateVariable::class)->handle($this->team, 'DB_PASSWORD', 'hunter2');
    app(AttachVariableToEnvironment::class)->handle($variable, $this->environment);

    $this->get('/acme/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('pendingEnvironments', 1)
            ->where('pendingEnvironments.0.project.slug', 'webshop')
            ->where('pendingEnvironments.0.environment.slug', 'production')
            ->where('pendingEnvironments.0.changes', 1)
            ->where('pendingEnvironments.0.version', null),
        );
});

it('drops an environment from the pending widget once it is published', function () {
    actingAsTeamMember(TeamRole::Owner, $this->team);

    $variable = app(CreateVariable::class)->handle($this->team, 'DB_PASSWORD', 'hunter2');
    app(AttachVariableToEnvironment::class)->handle($variable, $this->environment);
    app(PublishRelease::class)->handle($this->environment);

    $this->get('/acme/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('pendingEnvironments', 0));
});

it('shows the most recent releases without any plaintext', function () {
    actingAsTeamMember(TeamRole::Owner, $this->team);

    $variable = app(CreateVariable::class)->handle($this->team, 'DB_PASSWORD', 'hunter2');
    app(AttachVariableToEnvironment::class)->handle($variable, $this->environment);
    app(PublishRelease::class)->handle($this->environment, auth()->user(), 'First cut');

    $response = $this->get('/acme/dashboard')->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->has('recentReleases', 1)
        ->where('recentReleases.0.version', 1)
        ->where('recentReleases.0.message', 'First cut')
        ->where('recentReleases.0.environment.slug', 'production')
        ->where('recentReleases.0.variablesCount', 1),
    );

    $response->assertDontSee('hunter2');
});

it('lists usable deploy tokens and hides revoked ones', function () {
    actingAsTeamMember(TeamRole::Admin, $this->team);

    app(CreateDeployToken::class)->handle($this->environment, 'Ploi production');
    $revoked = app(CreateDeployToken::class)->handle($this->environment, 'Old laptop');
    $revoked->model->revoke();

    $this->get('/acme/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('deployTokens', 1)
            ->where('deployTokens.0.name', 'Ploi production')
            ->where('deployTokens.0.environment', $this->environment->name)
            ->where('deployTokens.0.lastUsedAt', null)
            ->where('stats.deployTokens', 1),
        );
});

it('shows recent audit activity to an admin', function () {
    actingAsTeamMember(TeamRole::Admin, $this->team);

    app(CreateVariable::class)->handle($this->team, 'DB_PASSWORD', 'hunter2', auth()->user());

    $this->get('/acme/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('recentActivity', 1)
            ->where('recentActivity.0.actor', auth()->user()->name),
        );
});

it('withholds audit activity from a member', function () {
    actingAsTeamMember(TeamRole::Member, $this->team);

    app(CreateVariable::class)->handle($this->team, 'DB_PASSWORD', 'hunter2', auth()->user());

    $this->get('/acme/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('recentActivity', null));
});

it('explains how the team key protects the stored variables', function () {
    actingAsTeamMember(TeamRole::Member, $this->team);

    app(CreateVariable::class)->handle($this->team, 'DB_PASSWORD', 'hunter2');

    $this->get('/acme/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('encryption.cipher', 'AES-256-GCM')
            ->where('encryption.scheme', 'v1')
            ->where('encryption.keyVersion', 1)
            ->whereNot('encryption.keyCreatedAt', null),
        );
});

it('reports no key version for a team that stored nothing yet', function () {
    actingAsTeamMember(TeamRole::Member, $this->team);

    $this->get('/acme/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('encryption.keyVersion', null)
            ->where('encryption.scheme', 'v1'),
        );

    expect($this->team->fresh()->currentKey())->toBeNull();
});

it('lists the secrets that are past their rotation interval', function () {
    actingAsTeamMember(TeamRole::Member, $this->team);

    $this->team->update(['default_rotate_after_days' => 30]);

    $this->travelTo(now()->subDays(100), function () {
        $old = app(CreateVariable::class)->handle($this->team, 'AWS_SECRET_ACCESS_KEY', 'old', ownerProject: $this->project);
        app(AttachVariableToEnvironment::class)->handle($old, $this->environment);
    });

    $fresh = app(CreateVariable::class)->handle($this->team, 'APP_NAME', 'Webshop');
    app(AttachVariableToEnvironment::class)->handle($fresh, $this->environment);

    $this->get('/acme/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('staleSecrets.total', 1)
            ->has('staleSecrets.rows', 1)
            ->where('staleSecrets.rows.0.key', 'AWS_SECRET_ACCESS_KEY')
            ->where('staleSecrets.rows.0.project', $this->project->name)
            ->where('staleSecrets.rows.0.overdueByDays', 70)
        );
});

it('reports nothing overdue while the team set no policy', function () {
    actingAsTeamMember(TeamRole::Member, $this->team);

    $this->travelTo(now()->subDays(1000), function () {
        $ancient = app(CreateVariable::class)->handle($this->team, 'AWS_SECRET_ACCESS_KEY', 'old');
        app(AttachVariableToEnvironment::class)->handle($ancient, $this->environment);
    });

    $this->get('/acme/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('staleSecrets.total', 0));
});
