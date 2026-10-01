<?php

use App\Actions\DeployTokens\CreateDeployToken;
use App\Actions\Variables\AttachVariableToEnvironment;
use App\Actions\Variables\CreateVariable;
use App\Enums\TeamRole;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Team;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->team = Team::factory()->create(['slug' => 'acme']);
    $this->project = Project::factory()->for($this->team)->create(['name' => 'Webshop', 'slug' => 'webshop']);
    $this->production = Environment::factory()->for($this->project)->create(['name' => 'Production', 'slug' => 'production']);
});

function searchTeam(string $query, string $team = 'acme'): TestResponse
{
    return test()->getJson("/{$team}/search?".http_build_query(['q' => $query]));
}

it('finds projects and environments by name or slug, case insensitively', function () {
    actingAsTeamMember(TeamRole::Member, $this->team);

    searchTeam('WEBSH')
        ->assertOk()
        ->assertJsonPath('projects.0.title', 'Webshop')
        ->assertJsonPath('projects.0.url', route('projects.show', ['current_team' => 'acme', 'project' => 'webshop']));

    searchTeam('produc')
        ->assertOk()
        ->assertJsonPath('environments.0.title', 'Production')
        ->assertJsonPath('environments.0.subtitle', 'Webshop');
});

it('finds a variable by key and points at the environment it lives in', function () {
    actingAsTeamMember(TeamRole::Member, $this->team);

    $variable = app(CreateVariable::class)->handle($this->team, 'DB_PASSWORD', 'hunter2');
    app(AttachVariableToEnvironment::class)->handle($variable, $this->production);

    searchTeam('db_pass')
        ->assertOk()
        ->assertJsonPath('variables.0.title', 'DB_PASSWORD')
        ->assertJsonPath('variables.0.subtitle', 'Webshop / Production')
        ->assertJsonPath('variables.0.url', route('environments.show', [
            'current_team' => 'acme',
            'project' => 'webshop',
            'environment' => 'production',
        ]));
});

it('finds a variable by the alias an environment uses for it', function () {
    actingAsTeamMember(TeamRole::Member, $this->team);

    $variable = app(CreateVariable::class)->handle($this->team, 'SHARED_MAIL_HOST', 'smtp.example.com');
    app(AttachVariableToEnvironment::class)->handle($variable, $this->production, aliasKey: 'MAIL_HOST');

    searchTeam('mail_host')
        ->assertOk()
        ->assertJsonCount(1, 'variables')
        ->assertJsonPath('variables.0.title', 'MAIL_HOST');
});

it('never searches or returns variable values', function () {
    actingAsTeamMember(TeamRole::Member, $this->team);

    $variable = app(CreateVariable::class)->handle($this->team, 'DB_PASSWORD', 'hunter2');
    app(AttachVariableToEnvironment::class)->handle($variable, $this->production);

    searchTeam('hunter2')->assertOk()->assertJsonCount(0, 'variables');

    expect(searchTeam('db_password')->getContent())->not->toContain('hunter2');
});

it('treats % and _ as text rather than wildcards', function () {
    actingAsTeamMember(TeamRole::Member, $this->team);

    Project::factory()->for($this->team)->create(['name' => 'Back_office', 'slug' => 'back-office']);
    Project::factory()->for($this->team)->create(['name' => 'Backxoffice', 'slug' => 'backxoffice']);

    searchTeam('k_o')->assertOk()->assertJsonCount(1, 'projects')->assertJsonPath('projects.0.title', 'Back_office');
    searchTeam('%%')->assertOk()->assertJsonCount(0, 'projects');
});

it('shows deploy tokens to someone who may manage them, and not to a member', function () {
    app(CreateDeployToken::class)->handle($this->production, 'Ploi production');

    actingAsTeamMember(TeamRole::Admin, $this->team);
    searchTeam('ploi')->assertOk()->assertJsonPath('deployTokens.0.title', 'Ploi production');

    actingAsTeamMember(TeamRole::Member, $this->team);
    searchTeam('ploi')->assertOk()->assertJsonCount(0, 'deployTokens');
});

it('leaves revoked deploy tokens out', function () {
    app(CreateDeployToken::class)->handle($this->production, 'Ploi production')->model->revoke();

    actingAsTeamMember(TeamRole::Admin, $this->team);

    searchTeam('ploi')->assertOk()->assertJsonCount(0, 'deployTokens');
});

it('only searches the current team', function () {
    $other = Team::factory()->create(['slug' => 'globex']);
    $otherProject = Project::factory()->for($other)->create(['name' => 'Webshop Globex']);
    $otherVariable = app(CreateVariable::class)->handle($other, 'DB_PASSWORD', 'secret');
    app(AttachVariableToEnvironment::class)->handle($otherVariable, Environment::factory()->for($otherProject)->create());

    actingAsTeamMember(TeamRole::Member, $this->team);

    searchTeam('webshop')->assertOk()->assertJsonCount(1, 'projects');
    searchTeam('db_password')->assertOk()->assertJsonCount(0, 'variables');
});

it('refuses someone outside the team', function () {
    actingAsTeamMember(TeamRole::Member, Team::factory()->create(['slug' => 'globex']));

    searchTeam('webshop')->assertForbidden();
});

it('needs at least two characters', function () {
    actingAsTeamMember(TeamRole::Member, $this->team);

    searchTeam('w')->assertUnprocessable()->assertJsonValidationErrors('q');
});
