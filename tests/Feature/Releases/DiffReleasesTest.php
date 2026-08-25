<?php

use App\Actions\Releases\DiffReleases;
use App\Actions\Releases\PublishRelease;
use App\Actions\Variables\AttachVariableToEnvironment;
use App\Actions\Variables\CreateVariable;
use App\Actions\Variables\UpdateVariableValue;
use App\Enums\ChangeType;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Team;

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->environment = Environment::factory()
        ->for(Project::factory()->for($this->team))
        ->create(['auto_publish' => false]);
});

function diffVariable(string $key, string $value)
{
    $variable = app(CreateVariable::class)->handle(test()->team, $key, $value);

    app(AttachVariableToEnvironment::class)->handle($variable, test()->environment);

    return $variable;
}

function diffAsMap(array $changes): array
{
    return collect($changes)->mapWithKeys(fn ($change) => [$change->key => $change->type->value])->all();
}

it('reports nothing when two releases are identical', function () {
    diffVariable('A', '1');
    $release = app(PublishRelease::class)->handle($this->environment);

    expect(app(DiffReleases::class)->handle($release, $release))->toBeEmpty();
});

it('reports an added key', function () {
    diffVariable('A', '1');
    $first = app(PublishRelease::class)->handle($this->environment);

    diffVariable('B', '2');
    $second = app(PublishRelease::class)->handle($this->environment->fresh());

    expect(diffAsMap(app(DiffReleases::class)->handle($first, $second)))
        ->toBe(['B' => 'added']);
});

it('reports a removed key', function () {
    $a = diffVariable('A', '1');
    diffVariable('B', '2');
    $first = app(PublishRelease::class)->handle($this->environment);

    $a->assignments()->delete();
    $second = app(PublishRelease::class)->handle($this->environment->fresh());

    expect(diffAsMap(app(DiffReleases::class)->handle($first, $second)))
        ->toBe(['A' => 'removed']);
});

it('reports a changed value', function () {
    $a = diffVariable('A', 'old');
    $first = app(PublishRelease::class)->handle($this->environment);

    app(UpdateVariableValue::class)->handle($a, 'new');
    $second = app(PublishRelease::class)->handle($this->environment->fresh());

    $changes = app(DiffReleases::class)->handle($first, $second);

    expect(diffAsMap($changes))->toBe(['A' => 'changed'])
        ->and($changes[0]->before)->toBe('old')
        ->and($changes[0]->after)->toBe('new');
});

it('leaves the values out unless they are explicitly requested', function () {
    $a = diffVariable('A', 'old');
    $first = app(PublishRelease::class)->handle($this->environment);

    app(UpdateVariableValue::class)->handle($a, 'new');
    $second = app(PublishRelease::class)->handle($this->environment->fresh());

    $changes = app(DiffReleases::class)->handle($first, $second, reveal: false);

    expect($changes[0]->type)->toBe(ChangeType::Changed)
        ->and($changes[0]->before)->toBeNull()
        ->and($changes[0]->after)->toBeNull();
});

it('sorts changes by key', function () {
    $first = app(PublishRelease::class)->handle($this->environment);

    diffVariable('ZED', '1');
    diffVariable('ALPHA', '2');
    $second = app(PublishRelease::class)->handle($this->environment->fresh());

    expect(array_keys(diffAsMap(app(DiffReleases::class)->handle($first, $second))))
        ->toBe(['ALPHA', 'ZED']);
});

it('diffs the pending changes against the last release', function () {
    $a = diffVariable('A', 'old');
    app(PublishRelease::class)->handle($this->environment);

    app(UpdateVariableValue::class)->handle($a, 'new');

    $changes = app(DiffReleases::class)->pending($this->environment->fresh());

    expect(diffAsMap($changes))->toBe(['A' => 'changed']);
});

it('treats everything as added when there is no release yet', function () {
    diffVariable('A', '1');

    expect(diffAsMap(app(DiffReleases::class)->pending($this->environment)))
        ->toBe(['A' => 'added']);
});
