<?php

use App\Actions\Variables\AttachVariableToEnvironment;
use App\Actions\Variables\CreateVariable;
use App\Actions\Variables\ResolveEnvironmentVariables;
use App\Actions\Variables\UpdateVariableValue;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Team;
use App\Models\Variable;

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->project = Project::factory()->for($this->team)->create();
    $this->environment = Environment::factory()->for($this->project)->create();
});

function envVariable(string $key, string $value, ?Team $team = null): Variable
{
    return app(CreateVariable::class)->handle($team ?? test()->team, $key, $value);
}

function attachTo(Variable $variable, ?Environment $environment = null, ?string $alias = null, int $order = 0): void
{
    app(AttachVariableToEnvironment::class)->handle(
        $variable,
        $environment ?? test()->environment,
        $alias,
        $order,
    );
}

function resolvedEnv(?Environment $environment = null): array
{
    return app(ResolveEnvironmentVariables::class)
        ->handle($environment ?? test()->environment)
        ->mapWithKeys(fn ($resolved) => [$resolved->key => $resolved->value()])
        ->all();
}

it('resolves an empty environment to nothing', function () {
    expect(resolvedEnv())->toBe([]);
});

it('resolves an assigned variable to its current value', function () {
    attachTo(envVariable('MAIL_PASSWORD', 'hunter2'));

    expect(resolvedEnv())->toBe(['MAIL_PASSWORD' => 'hunter2']);
});

it('follows the value when the variable gets a new version', function () {
    $mail = envVariable('MAIL_PASSWORD', 'first');
    attachTo($mail);

    app(UpdateVariableValue::class)->handle($mail, 'second');

    expect(resolvedEnv())->toBe(['MAIL_PASSWORD' => 'second']);
});

it('exposes a shared variable under its alias', function () {
    attachTo(envVariable('MAILGUN_SECRET', 'mg-key'), alias: 'MAIL_PASSWORD');

    expect(resolvedEnv())->toBe(['MAIL_PASSWORD' => 'mg-key']);
});

it('sorts variables by key so a rendered file is stable', function () {
    attachTo(envVariable('ZED', 'z'));
    attachTo(envVariable('ALPHA', 'a'));
    attachTo(envVariable('MIKE', 'm'));

    expect(array_keys(resolvedEnv()))->toBe(['ALPHA', 'MIKE', 'ZED']);
});

it('reaches every environment a shared variable is attached to', function () {
    $second = Environment::factory()->for(Project::factory()->for($this->team))->create();

    $shared = envVariable('SENTRY_DSN', 'https://sentry.example');
    attachTo($shared);
    attachTo($shared, $second);

    expect(resolvedEnv())->toBe(['SENTRY_DSN' => 'https://sentry.example'])
        ->and(resolvedEnv($second))->toBe(['SENTRY_DSN' => 'https://sentry.example']);

    app(UpdateVariableValue::class)->handle($shared, 'https://new.example');

    expect(resolvedEnv())->toBe(['SENTRY_DSN' => 'https://new.example'])
        ->and(resolvedEnv($second))->toBe(['SENTRY_DSN' => 'https://new.example']);
});

it('lets a project specific variable win over a shared one with the same key', function () {
    $otherEnvironment = Environment::factory()->for(Project::factory()->for($this->team))->create();

    $shared = envVariable('DATABASE_URL', 'shared-db');
    attachTo($shared);
    attachTo($shared, $otherEnvironment);

    $own = envVariable('DATABASE_URL', 'own-db');
    attachTo($own);

    expect(resolvedEnv())->toBe(['DATABASE_URL' => 'own-db'])
        ->and(resolvedEnv($otherEnvironment))->toBe(['DATABASE_URL' => 'shared-db']);
});

it('lets an alias collide with a real key and applies the same rule', function () {
    $shared = envVariable('MAILGUN_SECRET', 'from-shared');
    attachTo($shared, alias: 'MAIL_PASSWORD');
    attachTo($shared, Environment::factory()->for(Project::factory()->for($this->team))->create());

    attachTo(envVariable('MAIL_PASSWORD', 'from-own'));

    expect(resolvedEnv())->toBe(['MAIL_PASSWORD' => 'from-own']);
});

it('breaks a tie between two equally shared variables with the sort order', function () {
    $other = Environment::factory()->for(Project::factory()->for($this->team))->create();

    $first = envVariable('API_KEY', 'first');
    attachTo($first, order: 2);
    attachTo($first, $other);

    $second = envVariable('API_KEY', 'second');
    attachTo($second, order: 1);
    attachTo($second, $other);

    expect(resolvedEnv())->toBe(['API_KEY' => 'second']);
});

it('refuses to attach a variable to an environment of another team', function () {
    $foreign = Environment::factory()->for(Project::factory()->create())->create();

    expect(fn () => attachTo(envVariable('KEY', 'v'), $foreign))
        ->toThrow(InvalidArgumentException::class);
});

it('is idempotent when the same variable is attached twice', function () {
    $variable = envVariable('KEY', 'v');

    attachTo($variable);
    attachTo($variable, alias: 'RENAMED');

    expect($this->environment->assignments()->count())->toBe(1)
        ->and(resolvedEnv())->toBe(['RENAMED' => 'v']);
});

it('renders the resolved variables as a .env file', function () {
    attachTo(envVariable('APP_ENV', 'production'));
    attachTo(envVariable('DB_PASSWORD', 'p$ss word'));

    $rendered = app(ResolveEnvironmentVariables::class)->render($this->environment);

    expect(Dotenv\Dotenv::parse($rendered))->toBe([
        'APP_ENV' => 'production',
        'DB_PASSWORD' => 'p$ss word',
    ]);
});
