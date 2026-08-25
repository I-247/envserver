<?php

use App\Enums\TeamRole;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Team;
use Inertia\Testing\AssertableInertia as Assert;

it('requires authentication', function () {
    $team = Team::factory()->create();

    $this->get(route('projects.index', ['current_team' => $team->slug]))
        ->assertRedirect(route('login'));
});

it('lists only the projects of the current team', function () {
    $team = Team::factory()->create();
    actingAsTeamMember(TeamRole::Member, $team);

    $mine = Project::factory()->for($team)->create(['name' => 'Kluis Portaal']);
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
        'name' => 'Kluis Portaal',
    ])->assertRedirect();

    $project = Project::firstWhere('name', 'Kluis Portaal');

    expect($project->team_id)->toBe($team->id)
        ->and($project->slug)->toBe('kluis-portaal')
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
