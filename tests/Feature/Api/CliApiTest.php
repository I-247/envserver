<?php

use App\Actions\Releases\PublishRelease;
use App\Actions\Variables\AttachVariableToEnvironment;
use App\Actions\Variables\CreateVariable;
use App\Enums\TeamRole;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Models\Variable;
use Laravel\Passport\Passport;

beforeEach(function () {
    $this->team = Team::factory()->create(['slug' => 'acme']);
    $this->project = Project::factory()->for($this->team)->create(['slug' => 'webshop']);
    $this->environment = Environment::factory()->for($this->project)->create([
        'slug' => 'production',
        'auto_publish' => false,
    ]);

    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user, ['role' => TeamRole::Member->value]);
});

function apiPath(string $suffix = ''): string
{
    return "/api/v1/teams/acme/projects/webshop/environments/production{$suffix}";
}

function cliVariable(string $key, string $value): Variable
{
    $variable = app(CreateVariable::class)->handle(test()->team, $key, $value);

    app(AttachVariableToEnvironment::class)->handle($variable, test()->environment);

    return $variable;
}

function actingViaCli(array $scopes = ['projects:read', 'env:read', 'env:write', 'env:publish']): void
{
    Passport::actingAs(test()->user, $scopes);
}

it('rejects a request without a token', function () {
    $this->getJson('/api/v1/projects')->assertUnauthorized();
});

it('lists the projects of every team the user belongs to', function () {
    actingViaCli();

    Project::factory()->create(['slug' => 'someone-elses']);

    $this->getJson('/api/v1/projects')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.slug', 'webshop')
        ->assertJsonPath('data.0.team', 'acme')
        ->assertJsonPath('data.0.environments.0.slug', 'production');
});

it('refuses to list projects without the projects scope', function () {
    actingViaCli(['env:read']);

    $this->getJson('/api/v1/projects')->assertForbidden();
});

it('serves the latest release of an environment', function () {
    cliVariable('APP_ENV', 'production');
    app(PublishRelease::class)->handle($this->environment, $this->user);

    actingViaCli();

    $this->getJson(apiPath('/release'))
        ->assertOk()
        ->assertJsonPath('data.version', 1)
        ->assertJsonPath('data.variables.APP_ENV', 'production');
});

it('serves the pending state so the CLI can diff before publishing', function () {
    cliVariable('APP_ENV', 'production');

    actingViaCli();

    $this->getJson(apiPath('/pending'))
        ->assertOk()
        ->assertJsonPath('data.0.key', 'APP_ENV')
        ->assertJsonPath('data.0.type', 'added');
});

it('denies access to a team the user does not belong to', function () {
    $other = Team::factory()->create(['slug' => 'other']);
    Project::factory()->for($other)->create(['slug' => 'secret']);

    actingViaCli();

    $this->getJson('/api/v1/teams/other/projects/secret/environments/production/release')
        ->assertNotFound();
});

it('does not resolve a project from a different team even with a valid slug', function () {
    $other = Team::factory()->create(['slug' => 'other']);
    $other->members()->attach($this->user, ['role' => TeamRole::Member->value]);
    Project::factory()->for($other)->create(['slug' => 'webshop']);

    actingViaCli();

    $this->getJson('/api/v1/teams/other/projects/webshop/environments/production/release')
        ->assertNotFound();
});

describe('pushing variables', function () {
    it('creates variables that do not exist yet', function () {
        actingViaCli();

        $this->postJson(apiPath('/variables'), [
            'variables' => ['APP_ENV' => 'production', 'APP_DEBUG' => 'false'],
        ])->assertOk()
            ->assertJsonPath('data.created', 2)
            ->assertJsonPath('data.updated', 0);

        expect($this->environment->assignments()->count())->toBe(2);
    });

    it('updates a variable that already exists in this environment', function () {
        cliVariable('APP_ENV', 'staging');

        actingViaCli();

        $this->postJson(apiPath('/variables'), ['variables' => ['APP_ENV' => 'production']])
            ->assertOk()
            ->assertJsonPath('data.created', 0)
            ->assertJsonPath('data.updated', 1);

        expect(Variable::count())->toBe(1)
            ->and(Variable::sole()->currentVersion()->reveal())->toBe('production');
    });

    it('reports an unchanged variable as unchanged', function () {
        cliVariable('APP_ENV', 'production');

        actingViaCli();

        $this->postJson(apiPath('/variables'), ['variables' => ['APP_ENV' => 'production']])
            ->assertOk()
            ->assertJsonPath('data.unchanged', 1);
    });

    it('warns which other environments a shared variable change would reach', function () {
        $shared = cliVariable('SENTRY_DSN', 'old');
        $other = Environment::factory()->for(Project::factory()->for($this->team))->create();
        app(AttachVariableToEnvironment::class)->handle($shared, $other);

        actingViaCli();

        $this->postJson(apiPath('/variables'), ['variables' => ['SENTRY_DSN' => 'new']])
            ->assertOk()
            ->assertJsonCount(1, 'data.shared_impact');
    });

    it('refuses to push without the write scope', function () {
        actingViaCli(['env:read']);

        $this->postJson(apiPath('/variables'), ['variables' => ['A' => '1']])
            ->assertForbidden();
    });

    it('refuses a viewer', function () {
        $viewer = User::factory()->create();
        $this->team->members()->attach($viewer, ['role' => TeamRole::Viewer->value]);
        Passport::actingAs($viewer, ['env:write']);

        $this->postJson(apiPath('/variables'), ['variables' => ['A' => '1']])
            ->assertForbidden();
    });

    it('validates the payload', function () {
        actingViaCli();

        $this->postJson(apiPath('/variables'), ['variables' => ['lower case key' => '1']])
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('variables.lower case key');
    });
});

describe('publishing', function () {
    it('publishes a release', function () {
        cliVariable('APP_ENV', 'production');

        actingViaCli();

        $this->postJson(apiPath('/releases'), ['message' => 'vanuit de cli'])
            ->assertCreated()
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.message', 'vanuit de cli');
    });

    it('refuses to publish without the publish scope', function () {
        actingViaCli(['env:read', 'env:write']);

        $this->postJson(apiPath('/releases'))->assertForbidden();
    });
});
