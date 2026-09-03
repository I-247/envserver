<?php

use App\Actions\Environments\CompareEnvironments;
use App\Actions\Variables\AttachVariableToEnvironment;
use App\Actions\Variables\CreateVariable;
use App\Enums\TeamRole;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Team;
use App\Models\Variable;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->team = Team::factory()->create(['slug' => 'acme']);
    $this->project = Project::factory()->for($this->team)->create(['slug' => 'webshop']);

    $this->staging = Environment::factory()->for($this->project)->create([
        'slug' => 'staging',
        'auto_publish' => true,
        'sort_order' => 1,
    ]);

    $this->production = Environment::factory()->for($this->project)->create([
        'slug' => 'production',
        'auto_publish' => false,
        'sort_order' => 2,
    ]);
});

function driftVariable(string $key, string $value, Environment $environment): Variable
{
    $variable = app(CreateVariable::class)->handle(test()->team, $key, $value);

    app(AttachVariableToEnvironment::class)->handle($variable, $environment);

    return $variable;
}

function drift(): Collection
{
    return app(CompareEnvironments::class)->handle(test()->project);
}

it('reports a key one environment is missing', function () {
    driftVariable('SENTRY_DSN', 'https://sentry.example', $this->staging);

    $entry = drift()->sole();

    expect($entry->key)->toBe('SENTRY_DSN')
        ->and($entry->missingIn())->toBe(['production'])
        ->and($entry->isEverywhere())->toBeFalse();
});

it('gives environments that hold the same value the same group', function () {
    driftVariable('APP_NAME', 'Webshop', $this->staging);
    driftVariable('APP_NAME', 'Webshop', $this->production);

    $entry = drift()->sole();

    expect($entry->groups)->toBe(['staging' => 1, 'production' => 1])
        ->and($entry->differs())->toBeFalse();
});

it('gives environments that hold different values different groups', function () {
    driftVariable('DB_PASSWORD', 'staging-secret', $this->staging);
    driftVariable('DB_PASSWORD', 'production-secret', $this->production);

    $entry = drift()->sole();

    expect($entry->groups)->toBe(['staging' => 1, 'production' => 2])
        ->and($entry->differs())->toBeTrue()
        ->and($entry->reusedIn)->toBe([]);
});

it('flags a value a guarded environment shares with another one', function () {
    driftVariable('DB_PASSWORD', 'same-everywhere', $this->staging);
    driftVariable('DB_PASSWORD', 'same-everywhere', $this->production);

    expect(drift()->sole()->reusedIn)->toBe(['staging', 'production']);
});

it('leaves a duplicate alone when no guarded environment is involved', function () {
    $unguarded = Environment::factory()->for($this->project)->create([
        'slug' => 'review',
        'auto_publish' => true,
    ]);

    driftVariable('LOG_CHANNEL', 'stack', $this->staging);
    driftVariable('LOG_CHANNEL', 'stack', $unguarded);

    expect(drift()->sole()->reusedIn)->toBe([]);
});

it('follows the alias a variable is exposed under', function () {
    $variable = driftVariable('DATABASE_URL', 'postgres://one', $this->staging);

    $variable->assignments()->update(['alias_key' => 'DB_URL']);

    expect(drift()->sole()->key)->toBe('DB_URL');
});

it('shows the comparison in the portal without leaking a value or a checksum', function () {
    actingAsTeamMember(TeamRole::Member, $this->team);

    driftVariable('DB_PASSWORD', 'super-secret-value', $this->staging);
    driftVariable('DB_PASSWORD', 'super-secret-value', $this->production);
    driftVariable('SENTRY_DSN', 'https://sentry.example', $this->staging);

    $response = $this->get('/acme/projects/webshop/drift')->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->component('projects/drift')
        ->has('environments', 2)
        ->has('entries', 2)
        ->where('summary.keys', 2)
        ->where('summary.missing', 1)
        ->where('summary.reused', 1)
        ->where('entries.0.key', 'DB_PASSWORD')
        ->where('entries.0.reusedIn', ['staging', 'production'])
        ->where('entries.1.missingIn', ['production'])
    );

    $variable = Variable::query()->where('key', 'DB_PASSWORD')->first();

    expect($response->getContent())
        ->not->toContain('super-secret-value')
        ->not->toContain($variable->currentVersion()->checksum);
});

it('refuses somebody outside the team', function () {
    actingAsTeamMember(TeamRole::Owner);

    $this->get('/acme/projects/webshop/drift')->assertForbidden();
});
