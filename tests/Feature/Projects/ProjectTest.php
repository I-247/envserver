<?php

use App\Actions\Releases\PublishRelease;
use App\Actions\Variables\AttachVariableToEnvironment;
use App\Actions\Variables\CreateVariable;
use App\Enums\AuditAction;
use App\Enums\TeamRole;
use App\Models\DeployToken;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Team;
use App\Models\Variable;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

it('requires authentication', function () {
    $team = Team::factory()->create();

    $this->get(route('projects.index', ['current_team' => $team->slug]))
        ->assertRedirect(route('login'));
});

it('lists only the projects of the current team', function () {
    $team = Team::factory()->create();
    actingAsTeamMember(TeamRole::Member, $team);

    $mine = Project::factory()->for($team)->create(['name' => 'Envserver Portaal']);
    $theirs = Project::factory()->create(['name' => 'Andermans Project']);

    $this->get(route('projects.index', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/index')
            ->has('projects', 1)
            ->where('projects.0.slug', $mine->slug)
        );

    expect($theirs->team_id)->not->toBe($team->id);
});

it('creates a project with the three default environments', function () {
    $team = Team::factory()->create();
    actingAsTeamMember(TeamRole::Member, $team);

    $this->post(route('projects.store', ['current_team' => $team->slug]), [
        'name' => 'Envserver Portaal',
    ])->assertRedirect();

    $project = Project::firstWhere('name', 'Envserver Portaal');

    expect($project->team_id)->toBe($team->id)
        ->and($project->slug)->toBe('envserver-portaal')
        ->and($project->environments()->orderBy('sort_order')->pluck('slug')->all())
        ->toBe(['development', 'staging', 'production']);
});

it('only disables auto publishing on the production environment', function () {
    $team = Team::factory()->create();
    actingAsTeamMember(TeamRole::Member, $team);

    $this->post(route('projects.store', ['current_team' => $team->slug]), ['name' => 'Shop']);

    $environments = Environment::query()->pluck('auto_publish', 'slug');

    expect($environments['development'])->toBeTrue()
        ->and($environments['staging'])->toBeTrue()
        ->and($environments['production'])->toBeFalse();
});

it('keeps project slugs unique within a team', function () {
    $team = Team::factory()->create();
    actingAsTeamMember(TeamRole::Member, $team);

    $this->post(route('projects.store', ['current_team' => $team->slug]), ['name' => 'Shop']);
    $this->post(route('projects.store', ['current_team' => $team->slug]), ['name' => 'Shop']);

    expect(Project::pluck('slug')->all())->toBe(['shop', 'shop-2']);
});

it('allows the same project slug in a different team', function () {
    $teamA = Team::factory()->create();
    $teamB = Team::factory()->create();

    actingAsTeamMember(TeamRole::Member, $teamA);
    $this->post(route('projects.store', ['current_team' => $teamA->slug]), ['name' => 'Shop']);

    actingAsTeamMember(TeamRole::Member, $teamB);
    $this->post(route('projects.store', ['current_team' => $teamB->slug]), ['name' => 'Shop']);

    expect(Project::pluck('slug')->all())->toBe(['shop', 'shop']);
});

it('forbids a viewer from creating a project', function () {
    $team = Team::factory()->create();
    actingAsTeamMember(TeamRole::Viewer, $team);

    $this->post(route('projects.store', ['current_team' => $team->slug]), ['name' => 'Shop'])
        ->assertForbidden();

    expect(Project::count())->toBe(0);
});

it('forbids a member from deleting a project', function () {
    $team = Team::factory()->create();
    actingAsTeamMember(TeamRole::Member, $team);
    $project = Project::factory()->for($team)->create();

    $this->delete(route('projects.destroy', ['current_team' => $team->slug, 'project' => $project->slug]))
        ->assertForbidden();

    expect($project->fresh())->not->toBeNull();
});

it('lets an admin delete a project', function () {
    $team = Team::factory()->create();
    actingAsTeamMember(TeamRole::Admin, $team);
    $project = Project::factory()->for($team)->create();

    $this->delete(route('projects.destroy', ['current_team' => $team->slug, 'project' => $project->slug]))
        ->assertRedirect(route('projects.index', ['current_team' => $team->slug]));

    expect($project->fresh())->toBeNull();
});

it('removes variables that the deleted project leaves behind', function () {
    $team = Team::factory()->create();
    actingAsTeamMember(TeamRole::Admin, $team);

    $project = Project::factory()->for($team)->create();
    $environment = Environment::factory()->for($project)->create();

    $orphan = app(CreateVariable::class)->handle($team, 'ONLY_HERE', 'secret');
    app(AttachVariableToEnvironment::class)->handle($orphan, $environment);

    $this->delete(route('projects.destroy', ['current_team' => $team->slug, 'project' => $project->slug]))
        ->assertRedirect();

    expect(Variable::find($orphan->id))->toBeNull();

    $this->assertDatabaseHas('audit_events', [
        'team_id' => $team->id,
        'action' => AuditAction::ProjectDeleted->value,
    ]);
});

it('keeps a variable that another project still uses', function () {
    $team = Team::factory()->create();
    actingAsTeamMember(TeamRole::Admin, $team);

    $doomed = Project::factory()->for($team)->create();
    $survivor = Project::factory()->for($team)->create();
    $doomedEnvironment = Environment::factory()->for($doomed)->create();
    $survivingEnvironment = Environment::factory()->for($survivor)->create();

    $shared = app(CreateVariable::class)->handle($team, 'SHARED_KEY', 'secret');
    app(AttachVariableToEnvironment::class)->handle($shared, $doomedEnvironment);
    app(AttachVariableToEnvironment::class)->handle($shared, $survivingEnvironment);

    $this->delete(route('projects.destroy', ['current_team' => $team->slug, 'project' => $doomed->slug]))
        ->assertRedirect();

    expect(Variable::find($shared->id))->not->toBeNull()
        ->and($shared->assignments()->count())->toBe(1);
});

it('keeps a variable that a surviving release still pins', function () {
    $team = Team::factory()->create();
    actingAsTeamMember(TeamRole::Admin, $team);

    $doomed = Project::factory()->for($team)->create();
    $survivor = Project::factory()->for($team)->create();
    $doomedEnvironment = Environment::factory()->for($doomed)->create();
    $survivingEnvironment = Environment::factory()->for($survivor)->create();

    $variable = app(CreateVariable::class)->handle($team, 'ONCE_SHIPPED', 'secret');
    app(AttachVariableToEnvironment::class)->handle($variable, $doomedEnvironment);
    app(AttachVariableToEnvironment::class)->handle($variable, $survivingEnvironment);

    // The surviving environment shipped it once and then dropped it, so no
    // assignment is left but its release still has to stay reproducible.
    app(PublishRelease::class)->handle($survivingEnvironment);
    $survivingEnvironment->assignments()->where('variable_id', $variable->id)->delete();

    $this->delete(route('projects.destroy', ['current_team' => $team->slug, 'project' => $doomed->slug]))
        ->assertRedirect();

    expect(Variable::find($variable->id))->not->toBeNull()
        ->and($survivingEnvironment->latestRelease()->items()->count())->toBe(1);
});

it('keeps a never assigned variable that the project never touched', function () {
    $team = Team::factory()->create();
    actingAsTeamMember(TeamRole::Admin, $team);

    $project = Project::factory()->for($team)->create();
    Environment::factory()->for($project)->create();

    $unattached = app(CreateVariable::class)->handle($team, 'NOT_YET_USED', 'secret');

    $this->delete(route('projects.destroy', ['current_team' => $team->slug, 'project' => $project->slug]))
        ->assertRedirect();

    expect(Variable::find($unattached->id))->not->toBeNull();
});

it('does not resolve a project belonging to another team', function () {
    $team = Team::factory()->create();
    actingAsTeamMember(TeamRole::Owner, $team);
    $other = Project::factory()->create();

    $this->get(route('projects.show', ['current_team' => $team->slug, 'project' => $other->slug]))
        ->assertNotFound();
});

it('denies access to a team the user does not belong to', function () {
    actingAsTeamMember(TeamRole::Owner);
    $other = Team::factory()->create();

    $this->get(route('projects.index', ['current_team' => $other->slug]))
        ->assertForbidden();
});

it('shows a project with its environments', function () {
    $team = Team::factory()->create();
    actingAsTeamMember(TeamRole::Member, $team);
    $project = Project::factory()->for($team)->create();
    Environment::factory()->for($project)->create(['name' => 'Production']);

    $this->get(route('projects.show', ['current_team' => $team->slug, 'project' => $project->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/show')
            ->where('project.slug', $project->slug)
            ->has('project.environments', 1)
        );
});

it('counts the variables assigned to each environment', function () {
    $team = Team::factory()->create();
    actingAsTeamMember(TeamRole::Member, $team);
    $project = Project::factory()->for($team)->create();
    $environment = Environment::factory()->for($project)->create();

    foreach (['DB_PASSWORD', 'APP_KEY'] as $key) {
        app(AttachVariableToEnvironment::class)->handle(
            app(CreateVariable::class)->handle($team, $key, 'secret'),
            $environment,
        );
    }

    $this->get(route('projects.show', ['current_team' => $team->slug, 'project' => $project->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/show')
            ->where('project.environments.0.variableCount', 2)
        );
});

it('only exposes the edit and delete permissions to a user who has them', function () {
    $team = Team::factory()->create();
    actingAsTeamMember(TeamRole::Member, $team);
    $project = Project::factory()->for($team)->create();
    Environment::factory()->for($project)->create();

    $this->get(route('projects.show', ['current_team' => $team->slug, 'project' => $project->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('permissions.canUpdateProject', true)
            ->where('permissions.canDeleteProject', false)
        );
});

it('renames a project without changing its slug', function () {
    $team = Team::factory()->create();
    actingAsTeamMember(TeamRole::Member, $team);
    $project = Project::factory()->for($team)->create(['name' => 'Shop', 'slug' => 'shop']);

    $this->patch(route('projects.update', ['current_team' => $team->slug, 'project' => $project->slug]), [
        'name' => 'Webshop',
    ])->assertRedirect();

    expect($project->fresh())
        ->name->toBe('Webshop')
        ->slug->toBe('shop');
});

it('clears a project description when it is submitted empty', function () {
    $team = Team::factory()->create();
    actingAsTeamMember(TeamRole::Member, $team);
    $project = Project::factory()->for($team)->create(['description' => 'Old copy']);

    $this->patch(route('projects.update', ['current_team' => $team->slug, 'project' => $project->slug]), [
        'name' => $project->name,
        'description' => '',
    ])->assertRedirect();

    expect($project->fresh()->description)->toBeNull();
});

it('shows the most recent deploy token use as the project last deploy', function () {
    $team = Team::factory()->create();
    actingAsTeamMember(TeamRole::Member, $team);

    $project = Project::factory()->for($team)->create();
    $staging = Environment::factory()->for($project)->create();
    $production = Environment::factory()->for($project)->create();

    $newest = now()->subHour()->startOfSecond();

    makeDeployToken($staging, now()->subDays(3), 4);
    makeDeployToken($production, $newest, 7);
    makeDeployToken($production, null);

    $this->get(route('projects.index', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/index')
            ->where('projects.0.lastDeployedAt', $newest->toISOString())
            ->where('projects.0.deployCount', 11)
        );
});

it('lists the environments of each project', function () {
    $team = Team::factory()->create();
    actingAsTeamMember(TeamRole::Member, $team);

    $project = Project::factory()->for($team)->create();
    $staging = Environment::factory()->for($project)->create(['name' => 'Staging']);
    $production = Environment::factory()->for($project)->create(['name' => 'Production']);

    $this->get(route('projects.index', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/index')
            ->has('projects.0.environments', 2)
            ->where('projects.0.environments.0.slug', $staging->slug)
            ->where('projects.0.environments.0.name', 'Staging')
            ->where('projects.0.environments.1.slug', $production->slug)
        );
});

it('reports a project that was never deployed', function () {
    $team = Team::factory()->create();
    actingAsTeamMember(TeamRole::Member, $team);

    Environment::factory()->for(Project::factory()->for($team))->create();

    $this->get(route('projects.index', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/index')
            ->where('projects.0.lastDeployedAt', null)
            ->where('projects.0.deployCount', 0)
        );
});

function makeDeployToken(Environment $environment, ?CarbonInterface $lastUsedAt, int $useCount = 0): DeployToken
{
    return DeployToken::forceCreate([
        'environment_id' => $environment->id,
        'oauth_client_id' => Str::uuid()->toString(),
        'name' => 'Deployer',
        'scopes' => ['env:read'],
        'last_used_at' => $lastUsedAt,
        'use_count' => $useCount,
    ]);
}
