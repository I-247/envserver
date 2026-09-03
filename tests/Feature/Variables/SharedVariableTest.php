<?php

use App\Actions\Projects\DeleteProject;
use App\Actions\Variables\AttachVariableToEnvironment;
use App\Actions\Variables\CreateVariable;
use App\Actions\Variables\DetachVariableFromEnvironment;
use App\Actions\Variables\ResolveEnvironmentVariables;
use App\Actions\Variables\SetVariableShareable;
use App\Actions\Variables\ShareVariableWithEnvironment;
use App\Actions\Variables\UpdateVariableValue;
use App\Enums\AuditAction;
use App\Enums\TeamRole;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Team;
use App\Models\Variable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->team = Team::factory()->create(['slug' => 'acme']);

    $this->owner = Project::factory()->for($this->team)->create(['slug' => 'platform', 'name' => 'Platform']);
    $this->ownerEnvironment = Environment::factory()->for($this->owner)->create(['slug' => 'production']);

    $this->borrower = Project::factory()->for($this->team)->create(['slug' => 'webshop', 'name' => 'Webshop']);
    $this->borrowerEnvironment = Environment::factory()->for($this->borrower)->create(['slug' => 'production']);
});

function sharedVariable(string $key = 'MAILGUN_SECRET', string $value = 'hunter2', bool $offered = true): Variable
{
    $variable = app(CreateVariable::class)->handle(
        test()->team,
        $key,
        $value,
        ownerProject: test()->owner,
    );

    app(AttachVariableToEnvironment::class)->handle($variable, test()->ownerEnvironment);

    if ($offered) {
        app(SetVariableShareable::class)->handle($variable, test()->owner, true);
    }

    return $variable->refresh();
}

function shareUrl(?Environment $environment = null): string
{
    $environment ??= test()->borrowerEnvironment;

    return route('environments.variables.share', [
        'current_team' => 'acme',
        'project' => $environment->project->slug,
        'environment' => $environment->slug,
    ]);
}

/**
 * Fetch the share dialog's candidate list the way the dialog does: through a
 * partial reload, since shareable is an optional prop.
 *
 * @return list<array<string, mixed>>
 */
function shareableProps(?Environment $environment = null): array
{
    $environment ??= test()->borrowerEnvironment;

    return test()->withHeaders([
        'X-Inertia' => 'true',
        'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()),
        'X-Inertia-Partial-Component' => 'environments/show',
        'X-Inertia-Partial-Data' => 'shareable',
    ])->getJson(route('environments.show', [
        'current_team' => 'acme',
        'project' => $environment->project->slug,
        'environment' => $environment->slug,
    ]))->assertOk()->json('props.shareable');
}

it('stamps the creating project as the owner', function () {
    $variable = sharedVariable();

    expect($variable->owner_project_id)->toBe($this->owner->id)
        ->and($variable->isBorrowedBy($this->borrower))->toBeTrue()
        ->and($variable->isBorrowedBy($this->owner))->toBeFalse();
});

it('shares one variable with a second project rather than copying it', function () {
    $variable = sharedVariable();

    app(ShareVariableWithEnvironment::class)->handle($variable, $this->borrowerEnvironment);

    expect(Variable::where('key', 'MAILGUN_SECRET')->count())->toBe(1)
        ->and($variable->fresh()->usingProjectIds()->sort()->values()->all())
        ->toBe(collect([$this->owner->id, $this->borrower->id])->sort()->values()->all())
        ->and($variable->fresh()->isSharedAcrossProjects())->toBeTrue();
});

it('serves one value to both projects, so a change reaches each of them', function () {
    $variable = sharedVariable(value: 'first');
    app(ShareVariableWithEnvironment::class)->handle($variable, $this->borrowerEnvironment);

    app(UpdateVariableValue::class)->handle($variable, 'rotated');

    $resolve = app(ResolveEnvironmentVariables::class);

    expect($resolve->handle($this->ownerEnvironment)->first()->value())->toBe('rotated')
        ->and($resolve->handle($this->borrowerEnvironment)->first()->value())->toBe('rotated');
});

it('exposes a shared variable under an alias when asked', function () {
    $variable = sharedVariable();

    app(ShareVariableWithEnvironment::class)->handle(
        $variable,
        $this->borrowerEnvironment,
        aliasKey: 'MAIL_PASSWORD',
    );

    $resolved = app(ResolveEnvironmentVariables::class)
        ->handle($this->borrowerEnvironment)
        ->first();

    expect($resolved->key)->toBe('MAIL_PASSWORD')
        ->and($resolved->variable->key)->toBe('MAILGUN_SECRET');
});

it('lets a manager share a variable through the portal', function () {
    actingAsTeamMember(TeamRole::Admin, $this->team);
    $variable = sharedVariable();

    $this->post(shareUrl(), ['variable_id' => $variable->id])->assertRedirect();

    expect($this->borrowerEnvironment->assignments()->count())->toBe(1);

    $this->assertDatabaseHas('audit_events', [
        'team_id' => $this->team->id,
        'action' => AuditAction::VariableShared->value,
    ]);
});

it('offers only variables owned by other projects', function () {
    actingAsTeamMember(TeamRole::Admin, $this->team);
    sharedVariable();

    $own = app(CreateVariable::class)->handle($this->team, 'LOCAL_ONLY', 'x', ownerProject: $this->borrower);
    app(AttachVariableToEnvironment::class)->handle($own, $this->borrowerEnvironment);

    $offered = shareableProps();

    expect($offered)->toHaveCount(1)
        ->and($offered[0]['key'])->toBe('MAILGUN_SECRET')
        ->and($offered[0]['project'])->toBe('Platform');
});

it('refuses to share a variable the environment already exposes', function () {
    actingAsTeamMember(TeamRole::Admin, $this->team);
    $variable = sharedVariable();

    $clash = app(CreateVariable::class)->handle($this->team, 'MAILGUN_SECRET', 'other', ownerProject: $this->borrower);
    app(AttachVariableToEnvironment::class)->handle($clash, $this->borrowerEnvironment);

    $this->post(shareUrl(), ['variable_id' => $variable->id])
        ->assertSessionHasErrors('alias_key');

    expect($this->borrowerEnvironment->assignments()->count())->toBe(1);
});

it('never lets a variable cross a team boundary', function () {
    actingAsTeamMember(TeamRole::Admin, $this->team);

    $stranger = app(CreateVariable::class)->handle(Team::factory()->create(), 'OTHER_TEAM', 'x');

    $this->post(shareUrl(), ['variable_id' => $stranger->id])
        ->assertSessionHasErrors('variable_id');
});

it('keeps a shared variable alive for the other project when the owner drops it', function () {
    $variable = sharedVariable();
    app(ShareVariableWithEnvironment::class)->handle($variable, $this->borrowerEnvironment);

    $heir = app(DetachVariableFromEnvironment::class)->handle($variable, $this->ownerEnvironment);

    expect(Variable::find($variable->id))->not->toBeNull()
        ->and($heir?->id)->toBe($this->borrower->id)
        ->and($variable->fresh()->owner_project_id)->toBe($this->borrower->id)
        ->and($this->borrowerEnvironment->assignments()->count())->toBe(1);
});

it('records the handover in the audit trail', function () {
    $variable = sharedVariable();
    app(ShareVariableWithEnvironment::class)->handle($variable, $this->borrowerEnvironment);

    app(DetachVariableFromEnvironment::class)->handle($variable, $this->ownerEnvironment);

    $this->assertDatabaseHas('audit_events', [
        'team_id' => $this->team->id,
        'action' => AuditAction::VariableOwnershipTransferred->value,
    ]);
});

it('leaves ownership alone when the borrowing project drops the variable', function () {
    $variable = sharedVariable();
    app(ShareVariableWithEnvironment::class)->handle($variable, $this->borrowerEnvironment);

    $heir = app(DetachVariableFromEnvironment::class)->handle($variable, $this->borrowerEnvironment);

    expect($heir)->toBeNull()
        ->and($variable->fresh()->owner_project_id)->toBe($this->owner->id);
});

it('keeps ownership while the owner still uses the variable elsewhere', function () {
    $variable = sharedVariable();
    $staging = Environment::factory()->for($this->owner)->create(['slug' => 'staging']);
    app(AttachVariableToEnvironment::class)->handle($variable, $staging);
    app(ShareVariableWithEnvironment::class)->handle($variable, $this->borrowerEnvironment);

    $heir = app(DetachVariableFromEnvironment::class)->handle($variable, $this->ownerEnvironment);

    expect($heir)->toBeNull()
        ->and($variable->fresh()->owner_project_id)->toBe($this->owner->id);
});

it('hands a shared variable to the other project when the owner is deleted', function () {
    $variable = sharedVariable();
    app(ShareVariableWithEnvironment::class)->handle($variable, $this->borrowerEnvironment);

    $removed = app(DeleteProject::class)->handle($this->owner);

    expect($removed)->toBe([])
        ->and(Variable::find($variable->id))->not->toBeNull()
        ->and($variable->fresh()->owner_project_id)->toBe($this->borrower->id)
        ->and($variable->fresh()->currentVersion()->reveal())->toBe('hunter2');
});

it('still deletes a variable the deleted project shared with nobody', function () {
    $variable = sharedVariable(key: 'ONLY_HERE');

    $removed = app(DeleteProject::class)->handle($this->owner);

    expect($removed)->toBe(['ONLY_HERE'])
        ->and(Variable::find($variable->id))->toBeNull();
});

it('tells the environment page which project owns each variable', function () {
    actingAsTeamMember(TeamRole::Admin, $this->team);
    $variable = sharedVariable();
    app(ShareVariableWithEnvironment::class)->handle($variable, $this->borrowerEnvironment);

    $this->get(route('environments.show', [
        'current_team' => 'acme',
        'project' => 'webshop',
        'environment' => 'production',
    ]))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('variables.0.borrowed', true)
        ->where('variables.0.owner.name', 'Platform')
        ->where('variables.0.owner.slug', 'platform')
    );
});

it('keeps a new variable private to its project until the owner offers it', function () {
    actingAsTeamMember(TeamRole::Admin, $this->team);
    $variable = sharedVariable(offered: false);

    expect($variable->shareable)->toBeFalse()
        ->and($variable->isOfferedToOtherProjects())->toBeFalse()
        ->and(shareableProps())->toBe([]);
});

it('refuses to share a variable its owner never offered', function () {
    $variable = sharedVariable(offered: false);

    expect(fn () => app(ShareVariableWithEnvironment::class)
        ->handle($variable, $this->borrowerEnvironment))
        ->toThrow(InvalidArgumentException::class);

    expect($this->borrowerEnvironment->assignments()->count())->toBe(0);
});

it('rejects a share of an unoffered variable through the portal', function () {
    actingAsTeamMember(TeamRole::Admin, $this->team);
    $variable = sharedVariable(offered: false);

    $this->post(shareUrl(), ['variable_id' => $variable->id])
        ->assertSessionHasErrors('variable_id');

    expect($this->borrowerEnvironment->assignments()->count())->toBe(0);
});

it('offers a variable through the portal and then lists it', function () {
    actingAsTeamMember(TeamRole::Admin, $this->team);
    $variable = sharedVariable(offered: false);

    $this->patch(route('environments.variables.shareable', [
        'current_team' => 'acme',
        'project' => 'platform',
        'environment' => 'production',
        'variable' => $variable->id,
    ]), ['shareable' => true])->assertRedirect();

    expect($variable->fresh()->shareable)->toBeTrue()
        ->and(shareableProps())->toHaveCount(1);

    $this->assertDatabaseHas('audit_events', [
        'team_id' => $this->team->id,
        'action' => AuditAction::VariableOffered->value,
    ]);
});

it('stops offering a variable without reclaiming it from projects using it', function () {
    actingAsTeamMember(TeamRole::Admin, $this->team);
    $variable = sharedVariable();
    app(ShareVariableWithEnvironment::class)->handle($variable, $this->borrowerEnvironment);

    $this->patch(route('environments.variables.shareable', [
        'current_team' => 'acme',
        'project' => 'platform',
        'environment' => 'production',
        'variable' => $variable->id,
    ]), ['shareable' => false])->assertRedirect();

    expect($variable->fresh()->shareable)->toBeFalse()
        // The borrower keeps what it already deploys.
        ->and($this->borrowerEnvironment->assignments()->count())->toBe(1)
        ->and(app(ResolveEnvironmentVariables::class)
            ->handle($this->borrowerEnvironment)->first()->value())->toBe('hunter2');
});

it('does not let a borrowing project pass the variable on', function () {
    actingAsTeamMember(TeamRole::Admin, $this->team);
    $variable = sharedVariable();
    app(ShareVariableWithEnvironment::class)->handle($variable, $this->borrowerEnvironment);

    $this->patch(route('environments.variables.shareable', [
        'current_team' => 'acme',
        'project' => 'webshop',
        'environment' => 'production',
        'variable' => $variable->id,
    ]), ['shareable' => false])->assertForbidden();

    expect($variable->fresh()->shareable)->toBeTrue();
});

it('refuses at the action level when a borrower tries to change the offer', function () {
    $variable = sharedVariable();
    app(ShareVariableWithEnvironment::class)->handle($variable, $this->borrowerEnvironment);

    expect(fn () => app(SetVariableShareable::class)
        ->handle($variable, $this->borrower, false))
        ->toThrow(InvalidArgumentException::class);
});

it('lets the new owner keep offering the variable after a handover', function () {
    $variable = sharedVariable();
    app(ShareVariableWithEnvironment::class)->handle($variable, $this->borrowerEnvironment);

    app(DetachVariableFromEnvironment::class)->handle($variable, $this->ownerEnvironment);

    $variable->refresh();

    expect($variable->isOwnedBy($this->borrower))->toBeTrue()
        ->and(fn () => app(SetVariableShareable::class)
            ->handle($variable, $this->borrower, false))
        ->not->toThrow(InvalidArgumentException::class);
});

it('lets the borrowing project rename a shared variable without touching the value', function () {
    actingAsTeamMember(TeamRole::Admin, $this->team);
    $variable = sharedVariable();
    app(ShareVariableWithEnvironment::class)->handle($variable, $this->borrowerEnvironment);

    $this->patch(route('environments.variables.update', [
        'current_team' => 'acme',
        'project' => 'webshop',
        'environment' => 'production',
        'variable' => $variable->id,
    ]), ['alias_key' => 'MAIL_PASSWORD'])->assertRedirect();

    expect($this->borrowerEnvironment->assignments()->sole()->alias_key)->toBe('MAIL_PASSWORD')
        ->and($variable->fresh()->versions()->count())->toBe(1);
});

it('refuses to let the borrowing project change a value it does not own', function () {
    actingAsTeamMember(TeamRole::Admin, $this->team);
    $variable = sharedVariable();
    app(ShareVariableWithEnvironment::class)->handle($variable, $this->borrowerEnvironment);

    $this->patch(route('environments.variables.update', [
        'current_team' => 'acme',
        'project' => 'webshop',
        'environment' => 'production',
        'variable' => $variable->id,
    ]), ['value' => 'stolen'])->assertForbidden();

    expect($variable->fresh()->currentVersion()->reveal())->toBe('hunter2');
});
