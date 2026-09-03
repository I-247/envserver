<?php

use App\Actions\DeployTokens\CreateDeployToken;
use App\Actions\Releases\PublishRelease;
use App\Actions\Variables\AttachVariableToEnvironment;
use App\Actions\Variables\CreateVariable;
use App\Actions\Variables\DetachVariableFromEnvironment;
use App\Enums\AuditAction;
use App\Enums\TeamRole;
use App\Models\AuditEvent;
use App\Models\DeployToken;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Release;
use App\Models\Team;
use App\Models\Variable;

beforeEach(function () {
    $this->team = Team::factory()->create(['slug' => 'acme']);
    $this->project = Project::factory()->for($this->team)->create(['slug' => 'webshop']);
    $this->environment = Environment::factory()->for($this->project)->create([
        'name' => 'Staging',
        'slug' => 'staging',
        'auto_publish' => true,
    ]);
});

function manageUrl(?Environment $environment = null): string
{
    $environment ??= test()->environment;

    return '/acme/projects/webshop/environments/'.$environment->slug;
}

function environmentVariable(string $key, ?Environment $environment = null): Variable
{
    $variable = app(CreateVariable::class)->handle(test()->team, $key, 'value', ownerProject: test()->project);

    app(AttachVariableToEnvironment::class)->handle($variable, $environment ?? test()->environment);

    return $variable->refresh();
}

it('renames an environment without touching its slug', function () {
    actingAsTeamMember(TeamRole::Owner, $this->team);

    $this->patch(manageUrl(), ['name' => 'Acceptance', 'auto_publish' => '1'])
        ->assertRedirect(manageUrl());

    expect($this->environment->refresh()->name)->toBe('Acceptance')
        ->and($this->environment->slug)->toBe('staging');
});

it('turns auto publishing off', function () {
    actingAsTeamMember(TeamRole::Owner, $this->team);

    $this->patch(manageUrl(), ['name' => 'Staging'])->assertRedirect();

    expect($this->environment->refresh()->auto_publish)->toBeFalse();
});

it('records what changed about the environment', function () {
    $user = actingAsTeamMember(TeamRole::Owner, $this->team);

    $this->patch(manageUrl(), ['name' => 'Acceptance'])->assertRedirect();

    $event = AuditEvent::query()->where('action', AuditAction::EnvironmentUpdated)->sole();

    expect($event->actor_id)->toBe($user->id)
        ->and($event->metadata['from'])->toBe(['name' => 'Staging', 'auto_publish' => true, 'ip_allowlist' => []])
        ->and($event->metadata['to'])->toBe(['name' => 'Acceptance', 'auto_publish' => false, 'ip_allowlist' => []]);
});

it('records nothing when the form is submitted unchanged', function () {
    actingAsTeamMember(TeamRole::Owner, $this->team);

    $this->patch(manageUrl(), ['name' => 'Staging', 'auto_publish' => '1'])->assertRedirect();

    expect(AuditEvent::query()->where('action', AuditAction::EnvironmentUpdated)->count())->toBe(0);
});

it('refuses a rename by a member without permission', function () {
    actingAsTeamMember(TeamRole::Viewer, $this->team);

    $this->patch(manageUrl(), ['name' => 'Acceptance'])->assertForbidden();

    expect($this->environment->refresh()->name)->toBe('Staging');
});

it('deletes an environment with its releases and deploy tokens', function () {
    actingAsTeamMember(TeamRole::Owner, $this->team);

    environmentVariable('API_KEY');
    app(PublishRelease::class)->handle($this->environment);
    app(CreateDeployToken::class)->handle($this->environment, 'Ploi staging');

    $this->delete(manageUrl())->assertRedirect('/acme/projects/webshop');

    expect(Environment::query()->whereKey($this->environment->id)->exists())->toBeFalse()
        ->and(Release::query()->where('environment_id', $this->environment->id)->count())->toBe(0)
        ->and(DeployToken::query()->where('environment_id', $this->environment->id)->count())->toBe(0);
});

it('keeps a variable that a surviving release still pins', function () {
    actingAsTeamMember(TeamRole::Owner, $this->team);

    // The variable has already shipped somewhere else and was detached there,
    // so after this delete nothing is assigned to it while a release outside
    // this environment still needs its version to stay reproducible.
    $elsewhere = Environment::factory()->for($this->project)->create(['slug' => 'production']);

    $variable = environmentVariable('API_KEY');
    app(AttachVariableToEnvironment::class)->handle($variable, $elsewhere);
    app(PublishRelease::class)->handle($elsewhere);
    app(DetachVariableFromEnvironment::class)->handle($variable, $elsewhere);

    $this->delete(manageUrl())->assertRedirect();

    expect(Variable::query()->whereKey($variable->id)->exists())->toBeTrue()
        ->and($variable->refresh()->assignments()->count())->toBe(0);
});

it('removes a variable whose only release disappears with the environment', function () {
    actingAsTeamMember(TeamRole::Owner, $this->team);

    $variable = environmentVariable('API_KEY');
    app(PublishRelease::class)->handle($this->environment);

    $this->delete(manageUrl())->assertRedirect();

    expect(Variable::query()->whereKey($variable->id)->exists())->toBeFalse();
});

it('removes a variable nothing points at any more', function () {
    actingAsTeamMember(TeamRole::Owner, $this->team);

    $variable = environmentVariable('API_KEY');

    $this->delete(manageUrl())->assertRedirect();

    expect(Variable::query()->whereKey($variable->id)->exists())->toBeFalse();

    $event = AuditEvent::query()->where('action', AuditAction::EnvironmentDeleted)->sole();

    expect($event->metadata['removed_variables'])->toBe(['API_KEY']);
});

it('leaves a variable another environment of the same project still uses', function () {
    actingAsTeamMember(TeamRole::Owner, $this->team);

    $production = Environment::factory()->for($this->project)->create(['slug' => 'production']);

    $variable = environmentVariable('API_KEY');
    app(AttachVariableToEnvironment::class)->handle($variable, $production);

    $this->delete(manageUrl())->assertRedirect();

    expect($variable->refresh()->owner_project_id)->toBe($this->project->id)
        ->and($variable->assignments()->count())->toBe(1);

    $event = AuditEvent::query()->where('action', AuditAction::EnvironmentDeleted)->sole();

    expect($event->metadata['transferred_variables'])->toBe([]);
});

it('hands a shared variable to the project that keeps using it', function () {
    actingAsTeamMember(TeamRole::Owner, $this->team);

    $other = Project::factory()->for($this->team)->create(['slug' => 'billing']);
    $theirs = Environment::factory()->for($other)->create(['slug' => 'production']);

    $variable = environmentVariable('API_KEY');
    app(AttachVariableToEnvironment::class)->handle($variable, $theirs);

    $this->delete(manageUrl())->assertRedirect();

    expect($variable->refresh()->owner_project_id)->toBe($other->id);

    $event = AuditEvent::query()->where('action', AuditAction::EnvironmentDeleted)->sole();

    expect($event->metadata['transferred_variables'])->toBe(['API_KEY']);
});

it('refuses a delete by a member who may not delete the project', function () {
    actingAsTeamMember(TeamRole::Member, $this->team);

    $this->delete(manageUrl())->assertForbidden();

    expect(Environment::query()->whereKey($this->environment->id)->exists())->toBeTrue();
});
