<?php

use App\Actions\DeployTokens\CreateDeployToken;
use App\Actions\Releases\PublishRelease;
use App\Actions\Releases\RollbackToRelease;
use App\Actions\Variables\AttachVariableToEnvironment;
use App\Actions\Variables\CreateVariable;
use App\Actions\Variables\UpdateVariableValue;
use App\Enums\AuditAction;
use App\Enums\TeamRole;
use App\Models\AuditEvent;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Team;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->team = Team::factory()->create(['slug' => 'acme']);
    $this->project = Project::factory()->for($this->team)->create(['slug' => 'webshop']);
    $this->environment = Environment::factory()->for($this->project)->create(['slug' => 'production']);
});

function auditedVariable(string $key = 'DB_PASSWORD', string $value = 'hunter2')
{
    $variable = app(CreateVariable::class)->handle(test()->team, $key, $value, auth()->user());

    app(AttachVariableToEnvironment::class)->handle($variable, test()->environment);

    return $variable;
}

it('records who created a variable', function () {
    $user = actingAsTeamMember(TeamRole::Member, $this->team);
    auditedVariable();

    $event = AuditEvent::where('action', AuditAction::VariableCreated)->sole();

    expect($event->actor_id)->toBe($user->id)
        ->and($event->team_id)->toBe($this->team->id)
        ->and($event->metadata['key'])->toBe('DB_PASSWORD');
});

it('never records the value itself', function () {
    actingAsTeamMember(TeamRole::Member, $this->team);
    auditedVariable(value: 'super-secret-value');

    expect(AuditEvent::all()->toJson())->not->toContain('super-secret-value');
});

it('records a value change', function () {
    actingAsTeamMember(TeamRole::Member, $this->team);
    $variable = auditedVariable(value: 'first');

    app(UpdateVariableValue::class)->handle($variable, 'second', auth()->user());

    expect(AuditEvent::where('action', AuditAction::VariableUpdated)->count())->toBe(1);
});

it('does not record a save that changed nothing', function () {
    actingAsTeamMember(TeamRole::Member, $this->team);
    $variable = auditedVariable(value: 'same');

    app(UpdateVariableValue::class)->handle($variable, 'same', auth()->user());

    expect(AuditEvent::where('action', AuditAction::VariableUpdated)->count())->toBe(0);
});

it('records that someone looked at a secret', function () {
    $user = actingAsTeamMember(TeamRole::Member, $this->team);
    $variable = auditedVariable(value: 'super-secret-value');

    $this->getJson("/acme/projects/webshop/environments/production/variables/{$variable->id}/reveal")
        ->assertOk();

    $event = AuditEvent::where('action', AuditAction::SecretRevealed)->sole();

    expect($event->actor_id)->toBe($user->id)
        ->and($event->metadata['key'])->toBe('DB_PASSWORD')
        ->and($event->ip_address)->not->toBeNull();
});

it('records a published release', function () {
    $user = actingAsTeamMember(TeamRole::Member, $this->team);
    auditedVariable();

    app(PublishRelease::class)->handle($this->environment, $user, 'uitrol');

    $event = AuditEvent::where('action', AuditAction::ReleasePublished)->sole();

    expect($event->metadata['version'])->toBe(1)
        ->and($event->metadata['environment'])->toBe('production');
});

it('records an issued deploy token', function () {
    $user = actingAsTeamMember(TeamRole::Admin, $this->team);

    app(CreateDeployToken::class)->handle($this->environment, 'Ploi', $user);

    expect(AuditEvent::where('action', AuditAction::DeployTokenCreated)->count())->toBe(1)
        ->and(AuditEvent::all()->toJson())->not->toContain('client_secret');
});

it('shows the trail to an admin, newest first', function () {
    actingAsTeamMember(TeamRole::Admin, $this->team);
    auditedVariable('A');
    auditedVariable('B');

    $response = $this->get('/acme/audit')->assertOk();

    $response->assertInertia(fn (Assert $page) => $page->component('audit/index'));

    // Newest first. Attaching a variable auto publishes a release here, so the
    // very first row is a release rather than the variable itself; what
    // matters is that B's creation is listed above A's.
    $keys = collect($response->viewData('page')['props']['events'])
        ->pluck('metadata.key')
        ->filter()
        ->values();

    expect($keys->first())->toBe('B')
        ->and($keys->last())->toBe('A');
});

it('keeps the trail away from a plain member', function () {
    actingAsTeamMember(TeamRole::Member, $this->team);

    $this->get('/acme/audit')->assertForbidden();
});

it('never shows one team the trail of another', function () {
    actingAsTeamMember(TeamRole::Admin, $this->team);

    $otherTeam = Team::factory()->create();
    AuditEvent::create([
        'team_id' => $otherTeam->id,
        'action' => AuditAction::VariableCreated,
        'metadata' => ['key' => 'THEIR_SECRET'],
    ]);

    $response = $this->get('/acme/audit')->assertOk();

    expect($response->getContent())->not->toContain('THEIR_SECRET');
});

it('records a rollback regardless of who triggers it', function () {
    $user = actingAsTeamMember(TeamRole::Member, $this->team);
    $variable = auditedVariable('A', 'original');
    $first = app(PublishRelease::class)->handle($this->environment, $user);

    app(UpdateVariableValue::class)->handle($variable, 'broken', $user);

    // Straight through the action, bypassing the controller entirely.
    app(RollbackToRelease::class)->handle($first, $user);

    $event = AuditEvent::where('action', AuditAction::ReleaseRolledBack)->sole();

    expect($event->metadata['to'])->toBe($first->version)
        ->and($event->actor_id)->toBe($user->id)
        ->and($event->metadata['other_environments_affected'])->toBe(0);
});

it('records how far a shared rollback reached', function () {
    $user = actingAsTeamMember(TeamRole::Member, $this->team);
    $variable = auditedVariable('SENTRY_DSN', 'original');

    app(AttachVariableToEnvironment::class)->handle(
        $variable,
        Environment::factory()->for(Project::factory()->for($this->team))->create(),
    );

    $first = app(PublishRelease::class)->handle($this->environment, $user);
    app(UpdateVariableValue::class)->handle($variable, 'broken', $user);

    app(RollbackToRelease::class)->handle($first, $user);

    $event = AuditEvent::where('action', AuditAction::ReleaseRolledBack)->sole();

    expect($event->metadata['other_environments_affected'])->toBe(1);
});
