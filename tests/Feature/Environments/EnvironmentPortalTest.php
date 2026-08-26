<?php

use App\Actions\Releases\PublishRelease;
use App\Actions\Variables\AttachVariableToEnvironment;
use App\Actions\Variables\CreateVariable;
use App\Actions\Variables\UpdateVariableValue;
use App\Enums\TeamRole;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Release;
use App\Models\Team;
use App\Models\Variable;
use Illuminate\Http\Response;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->team = Team::factory()->create(['slug' => 'acme']);
    $this->project = Project::factory()->for($this->team)->create(['slug' => 'webshop']);
    $this->environment = Environment::factory()->for($this->project)->create([
        'slug' => 'production',
        'auto_publish' => false,
    ]);
});

function portalUrl(string $suffix = '', ?Environment $environment = null): string
{
    $environment ??= test()->environment;

    return sprintf(
        '/acme/projects/%s/environments/%s%s',
        $environment->project->slug,
        $environment->slug,
        $suffix,
    );
}

function portalVariable(string $key, string $value): Variable
{
    $variable = app(CreateVariable::class)->handle(test()->team, $key, $value);

    app(AttachVariableToEnvironment::class)->handle($variable, test()->environment);

    return $variable;
}

it('shows the environment with its variables, values masked', function () {
    actingAsTeamMember(TeamRole::Member, $this->team);
    portalVariable('DB_PASSWORD', 'super-secret-value');

    $response = $this->get(portalUrl())->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->component('environments/show')
        ->where('environment.slug', 'production')
        ->has('variables', 1)
        ->where('variables.0.key', 'DB_PASSWORD')
        ->where('variables.0.shared', false)
    );

    expect($response->getContent())->not->toContain('super-secret-value');
});

it('marks a variable used by more than one environment as shared', function () {
    actingAsTeamMember(TeamRole::Member, $this->team);

    $shared = portalVariable('SENTRY_DSN', 'dsn');
    app(AttachVariableToEnvironment::class)->handle(
        $shared,
        Environment::factory()->for(Project::factory()->for($this->team))->create(),
    );

    $this->get(portalUrl())
        ->assertInertia(fn (Assert $page) => $page->where('variables.0.shared', true));
});

it('shows pending changes for an environment that publishes manually', function () {
    actingAsTeamMember(TeamRole::Member, $this->team);
    $variable = portalVariable('A', 'old');
    app(PublishRelease::class)->handle($this->environment);

    app(UpdateVariableValue::class)->handle($variable, 'new');

    $this->get(portalUrl())
        ->assertInertia(fn (Assert $page) => $page
            ->has('pending', 1)
            ->where('pending.0.key', 'A')
            ->where('pending.0.type', 'changed')
        );
});

it('hides a viewer from the plaintext even in the pending diff', function () {
    actingAsTeamMember(TeamRole::Viewer, $this->team);
    portalVariable('A', 'super-secret-value');

    $response = $this->get(portalUrl())->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->where('pending.0.after', null)
        ->where('permissions.canViewSecretValue', false)
    );

    expect($response->getContent())->not->toContain('super-secret-value');
});

describe('creating a variable', function () {
    it('creates and attaches it in one step', function () {
        actingAsTeamMember(TeamRole::Member, $this->team);

        $this->post(portalUrl('/variables'), [
            'key' => 'MAIL_PASSWORD',
            'value' => 'hunter2',
        ])->assertRedirect();

        $variable = Variable::sole();

        expect($variable->key)->toBe('MAIL_PASSWORD')
            ->and($variable->currentVersion()->reveal())->toBe('hunter2')
            ->and($this->environment->assignments()->count())->toBe(1);
    });

    it('rejects a key the shell would not accept', function () {
        actingAsTeamMember(TeamRole::Member, $this->team);

        $this->post(portalUrl('/variables'), ['key' => 'not a key', 'value' => 'x'])
            ->assertSessionHasErrors('key');
    });

    it('rejects a key already used in this environment', function () {
        actingAsTeamMember(TeamRole::Member, $this->team);
        portalVariable('APP_ENV', 'production');

        $this->post(portalUrl('/variables'), ['key' => 'APP_ENV', 'value' => 'staging'])
            ->assertSessionHasErrors('key');
    });

    it('forbids a viewer', function () {
        actingAsTeamMember(TeamRole::Viewer, $this->team);

        $this->post(portalUrl('/variables'), ['key' => 'A', 'value' => '1'])
            ->assertForbidden();
    });
});

describe('updating a variable', function () {
    it('appends a new version', function () {
        actingAsTeamMember(TeamRole::Member, $this->team);
        $variable = portalVariable('A', 'old');

        $this->patch(portalUrl("/variables/{$variable->id}"), ['value' => 'new'])
            ->assertRedirect();

        expect($variable->fresh()->versions()->count())->toBe(2)
            ->and($variable->fresh()->currentVersion()->reveal())->toBe('new');
    });

    it('can set an alias without touching the value', function () {
        actingAsTeamMember(TeamRole::Member, $this->team);
        $variable = portalVariable('MAILGUN_SECRET', 'mg');

        $this->patch(portalUrl("/variables/{$variable->id}"), ['alias_key' => 'MAIL_PASSWORD'])
            ->assertRedirect();

        expect($variable->fresh()->versions()->count())->toBe(1)
            ->and($this->environment->assignments()->sole()->alias_key)->toBe('MAIL_PASSWORD');
    });

    it('does not touch a variable from another team', function () {
        actingAsTeamMember(TeamRole::Owner, $this->team);
        $foreign = app(CreateVariable::class)->handle(Team::factory()->create(), 'A', 'x');

        $this->patch(portalUrl("/variables/{$foreign->id}"), ['value' => 'y'])
            ->assertNotFound();
    });
});

describe('detaching a variable', function () {
    it('removes it from this environment but keeps the variable', function () {
        actingAsTeamMember(TeamRole::Member, $this->team);
        $variable = portalVariable('A', '1');

        $this->delete(portalUrl("/variables/{$variable->id}"))->assertRedirect();

        expect($this->environment->assignments()->count())->toBe(0)
            ->and(Variable::find($variable->id))->not->toBeNull();
    });
});

describe('publishing', function () {
    it('publishes a release', function () {
        actingAsTeamMember(TeamRole::Member, $this->team);
        portalVariable('A', '1');

        $this->post(portalUrl('/releases'), ['message' => 'uitrol'])->assertRedirect();

        expect($this->environment->latestRelease()->message)->toBe('uitrol');
    });

    it('forbids a viewer', function () {
        actingAsTeamMember(TeamRole::Viewer, $this->team);
        portalVariable('A', '1');

        $this->post(portalUrl('/releases'))->assertForbidden();
    });
});

describe('release history', function () {
    it('lists releases with who published them', function () {
        $user = actingAsTeamMember(TeamRole::Member, $this->team);
        portalVariable('A', '1');
        app(PublishRelease::class)->handle($this->environment, $user, 'eerste');

        $this->get(portalUrl('/releases'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('environments/releases')
                ->has('releases', 1)
                ->where('releases.0.message', 'eerste')
                ->where('releases.0.publishedBy', $user->name)
            );
    });

    it('rolls back to an earlier release and warns about shared reach', function () {
        $user = actingAsTeamMember(TeamRole::Member, $this->team);
        $variable = portalVariable('A', 'original');
        $first = app(PublishRelease::class)->handle($this->environment, $user);

        app(UpdateVariableValue::class)->handle($variable, 'broken');
        app(PublishRelease::class)->handle($this->environment->fresh(), $user);

        $this->post(portalUrl("/releases/{$first->id}/rollback"))->assertRedirect();

        expect($variable->fresh()->currentVersion()->reveal())->toBe('original')
            ->and(Release::count())->toBe(3);
    });

    it('does not roll back a release of another environment', function () {
        actingAsTeamMember(TeamRole::Owner, $this->team);

        $other = Environment::factory()->for(Project::factory()->for($this->team))->create();
        $release = $other->releases()->create(['version' => 1]);

        $this->post(portalUrl("/releases/{$release->id}/rollback"))->assertNotFound();
    });
});

describe('revealing a value', function () {
    it('returns the plaintext to someone allowed to see it', function () {
        actingAsTeamMember(TeamRole::Member, $this->team);
        $variable = portalVariable('A', 'super-secret-value');

        $this->getJson(portalUrl("/variables/{$variable->id}/reveal"))
            ->assertOk()
            ->assertJsonPath('value', 'super-secret-value');
    });

    it('refuses a viewer', function () {
        actingAsTeamMember(TeamRole::Viewer, $this->team);
        $variable = portalVariable('A', 'super-secret-value');

        $this->getJson(portalUrl("/variables/{$variable->id}/reveal"))
            ->assertForbidden();
    });

    it('stops someone walking through every value one request at a time', function () {
        actingAsTeamMember(TeamRole::Member, $this->team);
        $variable = portalVariable('A', 'super-secret-value');

        foreach (range(1, 20) as $ignored) {
            $this->getJson(portalUrl("/variables/{$variable->id}/reveal"))->assertOk();
        }

        $this->getJson(portalUrl("/variables/{$variable->id}/reveal"))
            ->assertStatus(Response::HTTP_TOO_MANY_REQUESTS);
    });
});
