<?php

use App\Actions\Variables\AttachVariableToEnvironment;
use App\Actions\Variables\CreateVariable;
use App\Enums\TeamRole;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Team;
use App\Models\Variable;

beforeEach(function () {
    $this->team = Team::factory()->create(['slug' => 'acme']);
    $this->project = Project::factory()->for($this->team)->create(['slug' => 'webshop']);
    $this->environment = Environment::factory()->for($this->project)->create(['slug' => 'production']);
});

function importUrl(string $suffix = ''): string
{
    return "/acme/projects/webshop/environments/production/variables/import{$suffix}";
}

function existingVariable(string $key, string $value): Variable
{
    $variable = app(CreateVariable::class)->handle(test()->team, $key, $value);

    app(AttachVariableToEnvironment::class)->handle($variable, test()->environment);

    return $variable;
}

it('creates every variable in the paste', function () {
    actingAsTeamMember(TeamRole::Admin, $this->team);

    $this->post(importUrl(), [
        'contents' => "APP_ENV=production\nDB_PASSWORD=\"p\$ssw0rd\"\n",
    ])->assertRedirect();

    $variables = $this->environment->assignments()->with('variable')->get()->pluck('variable');

    expect($variables->pluck('key')->sort()->values()->all())->toBe(['APP_ENV', 'DB_PASSWORD'])
        ->and($variables->firstWhere('key', 'DB_PASSWORD')->currentVersion()->reveal())->toBe('p$ssw0rd');
});

it('overwrites a conflicting variable when asked to', function () {
    actingAsTeamMember(TeamRole::Admin, $this->team);
    $existing = existingVariable('APP_ENV', 'local');

    $this->post(importUrl(), [
        'contents' => "APP_ENV=production\n",
        'strategy' => 'overwrite',
    ])->assertRedirect();

    expect($existing->refresh()->currentVersion()->reveal())->toBe('production')
        ->and($existing->versions()->count())->toBe(2);
});

it('keeps the vault value and only adds what is new', function () {
    actingAsTeamMember(TeamRole::Admin, $this->team);
    $existing = existingVariable('APP_ENV', 'local');

    $this->post(importUrl(), [
        'contents' => "APP_ENV=production\nAPP_DEBUG=false\n",
        'strategy' => 'keep',
    ])->assertRedirect();

    expect($existing->refresh()->currentVersion()->reveal())->toBe('local')
        ->and($existing->versions()->count())->toBe(1)
        ->and($this->environment->assignments()->count())->toBe(2);
});

it('overwrites by default when no strategy is given', function () {
    actingAsTeamMember(TeamRole::Admin, $this->team);
    $existing = existingVariable('APP_ENV', 'local');

    $this->post(importUrl(), ['contents' => "APP_ENV=production\n"])->assertRedirect();

    expect($existing->refresh()->currentVersion()->reveal())->toBe('production');
});

it('matches a conflict on the alias a variable is exposed under', function () {
    actingAsTeamMember(TeamRole::Admin, $this->team);
    $variable = app(CreateVariable::class)->handle($this->team, 'SHARED_DB_PASSWORD', 'old');
    app(AttachVariableToEnvironment::class)->handle($variable, $this->environment, 'DB_PASSWORD');

    $this->post(importUrl(), ['contents' => "DB_PASSWORD=new\n"])->assertRedirect();

    expect($variable->refresh()->currentVersion()->reveal())->toBe('new')
        ->and($this->environment->assignments()->count())->toBe(1);
});

it('reports which keys are new and which ones collide, without any value', function () {
    actingAsTeamMember(TeamRole::Admin, $this->team);
    existingVariable('APP_ENV', 'local');

    $response = $this->postJson(importUrl('/preview'), [
        'contents' => "APP_ENV=production\nAPP_DEBUG=false\n",
    ])->assertOk();

    $response->assertJson(['adding' => ['APP_DEBUG'], 'conflicting' => ['APP_ENV']]);

    expect($response->getContent())->not->toContain('local')
        ->and($response->getContent())->not->toContain('production');
});

it('changes nothing while previewing', function () {
    actingAsTeamMember(TeamRole::Admin, $this->team);

    $this->postJson(importUrl('/preview'), ['contents' => "APP_ENV=production\n"])->assertOk();

    expect($this->environment->assignments()->count())->toBe(0);
});

it('rejects a paste that does not read as a .env file', function () {
    actingAsTeamMember(TeamRole::Admin, $this->team);

    $this->post(importUrl(), ['contents' => "not a env file at all\n"])
        ->assertSessionHasErrors('contents');

    expect($this->environment->assignments()->count())->toBe(0);
});

it('rejects a paste without a single variable', function () {
    actingAsTeamMember(TeamRole::Admin, $this->team);

    $this->post(importUrl(), ['contents' => "# only a comment\n"])
        ->assertSessionHasErrors('contents');
});

it('does not let a viewer import', function () {
    actingAsTeamMember(TeamRole::Viewer, $this->team);

    $this->post(importUrl(), ['contents' => "APP_ENV=production\n"])->assertForbidden();
    $this->postJson(importUrl('/preview'), ['contents' => "APP_ENV=production\n"])->assertForbidden();

    expect($this->environment->assignments()->count())->toBe(0);
});
