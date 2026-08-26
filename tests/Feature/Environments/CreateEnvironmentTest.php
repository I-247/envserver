<?php

use App\Enums\AuditAction;
use App\Enums\TeamRole;
use App\Models\AuditEvent;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Team;

beforeEach(function () {
    $this->team = Team::factory()->create(['slug' => 'acme']);
    $this->project = Project::factory()->for($this->team)->create(['slug' => 'webshop']);
});

function createEnvironmentUrl(): string
{
    return '/acme/projects/webshop/environments';
}

it('adds an environment with auto publishing on', function () {
    actingAsTeamMember(TeamRole::Owner, $this->team);

    $this->post(createEnvironmentUrl(), ['name' => 'Acceptance', 'auto_publish' => '1'])
        ->assertRedirect('/acme/projects/webshop/environments/acceptance');

    $environment = $this->project->environments()->where('slug', 'acceptance')->sole();

    expect($environment->name)->toBe('Acceptance')
        ->and($environment->auto_publish)->toBeTrue();
});

it('leaves auto publishing off when the checkbox is not submitted', function () {
    actingAsTeamMember(TeamRole::Owner, $this->team);

    $this->post(createEnvironmentUrl(), ['name' => 'Acceptance'])->assertRedirect();

    expect($this->project->environments()->sole()->auto_publish)->toBeFalse();
});

it('places a new environment behind the ones that already exist', function () {
    actingAsTeamMember(TeamRole::Owner, $this->team);

    Environment::factory()->for($this->project)->create(['slug' => 'production', 'sort_order' => 3]);

    $this->post(createEnvironmentUrl(), ['name' => 'Acceptance'])->assertRedirect();

    expect($this->project->environments()->pluck('slug')->all())
        ->toBe(['production', 'acceptance']);
});

it('gives a duplicate name its own slug instead of failing on the unique index', function () {
    actingAsTeamMember(TeamRole::Owner, $this->team);

    Environment::factory()->for($this->project)->create(['name' => 'Staging', 'slug' => 'staging']);

    $this->post(createEnvironmentUrl(), ['name' => 'Staging'])
        ->assertRedirect('/acme/projects/webshop/environments/staging-2');
});

it('records who added the environment', function () {
    $user = actingAsTeamMember(TeamRole::Owner, $this->team);

    $this->post(createEnvironmentUrl(), ['name' => 'Acceptance'])->assertRedirect();

    $event = AuditEvent::query()->where('action', AuditAction::EnvironmentCreated)->sole();

    expect($event->actor_id)->toBe($user->id)
        ->and($event->metadata['environment'])->toBe('Acceptance')
        ->and($event->metadata['auto_publish'])->toBeFalse();
});

it('requires a name', function () {
    actingAsTeamMember(TeamRole::Owner, $this->team);

    $this->post(createEnvironmentUrl(), ['name' => ''])->assertSessionHasErrors('name');

    expect($this->project->environments()->count())->toBe(0);
});

it('refuses a member without permission to change the project', function () {
    actingAsTeamMember(TeamRole::Viewer, $this->team);

    $this->post(createEnvironmentUrl(), ['name' => 'Acceptance'])->assertForbidden();

    expect($this->project->environments()->count())->toBe(0);
});
