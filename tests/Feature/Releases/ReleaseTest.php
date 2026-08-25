<?php

use App\Actions\Releases\PublishRelease;
use App\Actions\Releases\RollbackToRelease;
use App\Actions\Variables\AttachVariableToEnvironment;
use App\Actions\Variables\CreateVariable;
use App\Actions\Variables\UpdateVariableValue;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Release;
use App\Models\Team;
use App\Models\User;
use App\Models\Variable;

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->project = Project::factory()->for($this->team)->create();
    $this->environment = Environment::factory()->for($this->project)->create(['auto_publish' => false]);
    $this->user = User::factory()->create();
});

function newVariable(string $key, string $value): Variable
{
    return app(CreateVariable::class)->handle(test()->team, $key, $value);
}

function assign(Variable $variable, ?Environment $environment = null): void
{
    app(AttachVariableToEnvironment::class)->handle($variable, $environment ?? test()->environment);
}

function publish(?Environment $environment = null, ?string $message = null): ?Release
{
    return app(PublishRelease::class)->handle(
        $environment ?? test()->environment,
        test()->user,
        $message,
    );
}

function releaseMap(Release $release): array
{
    return $release->items
        ->mapWithKeys(fn ($item) => [$item->key => $item->version->reveal()])
        ->all();
}

it('publishes a release that snapshots the current values', function () {
    assign(newVariable('APP_ENV', 'production'));
    assign(newVariable('DB_PASSWORD', 'hunter2'));

    $release = publish(message: 'eerste uitrol');

    expect($release->version)->toBe(1)
        ->and($release->message)->toBe('eerste uitrol')
        ->and($release->published_by)->toBe($this->user->id)
        ->and(releaseMap($release))->toBe([
            'APP_ENV' => 'production',
            'DB_PASSWORD' => 'hunter2',
        ]);
});

it('pins the exact version so a later change does not rewrite history', function () {
    $password = newVariable('DB_PASSWORD', 'old');
    assign($password);

    $release = publish();

    app(UpdateVariableValue::class)->handle($password, 'new');

    expect(releaseMap($release->fresh('items')))->toBe(['DB_PASSWORD' => 'old']);
});

it('numbers releases per environment', function () {
    $other = Environment::factory()->for($this->project)->create(['auto_publish' => false]);

    assign(newVariable('A', '1'));
    assign(newVariable('B', '2'), $other);

    expect(publish()->version)->toBe(1)
        ->and(publish($other)->version)->toBe(1);
});

it('does not publish a release when nothing changed', function () {
    assign(newVariable('A', '1'));

    $first = publish();
    $second = publish();

    expect($second->id)->toBe($first->id)
        ->and(Release::count())->toBe(1);
});

it('publishes a new release once a value actually changes', function () {
    $variable = newVariable('A', '1');
    assign($variable);
    publish();

    app(UpdateVariableValue::class)->handle($variable, '2');

    expect(publish()->version)->toBe(2);
});

it('publishes a new release when a variable is added', function () {
    assign(newVariable('A', '1'));
    publish();

    assign(newVariable('B', '2'));

    expect(publish()->version)->toBe(2)
        ->and(Release::count())->toBe(2);
});

it('reports pending changes before they are published', function () {
    $variable = newVariable('A', '1');
    assign($variable);

    expect($this->environment->hasPendingChanges())->toBeTrue();

    publish();

    expect($this->environment->fresh()->hasPendingChanges())->toBeFalse();

    app(UpdateVariableValue::class)->handle($variable, '2');

    expect($this->environment->fresh()->hasPendingChanges())->toBeTrue();
});

it('renders a release as a .env file', function () {
    assign(newVariable('APP_ENV', 'production'));
    assign(newVariable('DB_PASSWORD', 'p$ss word'));

    expect(Dotenv\Dotenv::parse(publish()->toEnvFile()))->toBe([
        'APP_ENV' => 'production',
        'DB_PASSWORD' => 'p$ss word',
    ]);
});

describe('automatic publishing', function () {
    it('publishes automatically for an environment that opted in', function () {
        $auto = Environment::factory()->for($this->project)->create(['auto_publish' => true]);

        $variable = newVariable('A', '1');
        assign($variable, $auto);

        expect($auto->releases()->count())->toBe(1);

        app(UpdateVariableValue::class)->handle($variable, '2');

        expect($auto->fresh()->releases()->count())->toBe(2)
            ->and(releaseMap($auto->latestRelease()))->toBe(['A' => '2']);
    });

    it('leaves a manual environment pending instead of publishing', function () {
        $variable = newVariable('A', '1');
        assign($variable);
        publish();

        app(UpdateVariableValue::class)->handle($variable, '2');

        expect($this->environment->releases()->count())->toBe(1)
            ->and($this->environment->fresh()->hasPendingChanges())->toBeTrue()
            ->and(releaseMap($this->environment->latestRelease()))->toBe(['A' => '1']);
    });

    it('reaches every opted in environment sharing the variable in one change', function () {
        $shared = newVariable('SENTRY_DSN', 'old');

        $environments = collect(range(1, 3))->map(function () use ($shared) {
            $environment = Environment::factory()
                ->for(Project::factory()->for($this->team))
                ->create(['auto_publish' => true]);

            assign($shared, $environment);

            return $environment;
        });

        app(UpdateVariableValue::class)->handle($shared, 'new');

        $environments->each(fn (Environment $environment) => expect(
            releaseMap($environment->fresh()->latestRelease())
        )->toBe(['SENTRY_DSN' => 'new']));
    });
});

describe('rollback', function () {
    it('restores the old values by appending new versions', function () {
        $variable = newVariable('A', 'original');
        assign($variable);
        $first = publish();

        app(UpdateVariableValue::class)->handle($variable, 'broken');
        publish();

        $restored = app(RollbackToRelease::class)->handle($first, $this->user);

        expect(releaseMap($restored))->toBe(['A' => 'original'])
            ->and($restored->version)->toBe(3)
            ->and($variable->fresh()->currentVersion()->reveal())->toBe('original')
            ->and($variable->fresh()->versions()->count())->toBe(3);
    });

    it('never deletes history', function () {
        $variable = newVariable('A', 'original');
        assign($variable);
        $first = publish();

        app(UpdateVariableValue::class)->handle($variable, 'broken');
        publish();

        app(RollbackToRelease::class)->handle($first, $this->user);

        expect(Release::count())->toBe(3)
            ->and(Release::where('version', 2)->exists())->toBeTrue();
    });

    it('warns which other environments a rollback would also change', function () {
        $other = Environment::factory()
            ->for(Project::factory()->for($this->team))
            ->create(['auto_publish' => false]);

        $shared = newVariable('SENTRY_DSN', 'original');
        assign($shared);
        assign($shared, $other);

        $first = publish();
        app(UpdateVariableValue::class)->handle($shared, 'changed');

        $impact = app(RollbackToRelease::class)->sharedImpact($first);

        expect($impact->pluck('id')->all())->toBe([$other->id]);
    });

    it('reports no impact when nothing is shared', function () {
        assign(newVariable('A', 'original'));

        expect(app(RollbackToRelease::class)->sharedImpact(publish()))->toBeEmpty();
    });
});
