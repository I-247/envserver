<?php

use App\Actions\Variables\CreateVariable;
use App\Actions\Variables\UpdateVariableValue;
use App\Models\Team;
use App\Models\User;
use App\Models\Variable;
use App\Models\VariableVersion;

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->author = User::factory()->create();
});

function createVariable(string $key = 'MAIL_PASSWORD', string $value = 'hunter2'): Variable
{
    return app(CreateVariable::class)->handle(
        test()->team,
        $key,
        $value,
        test()->author,
    );
}

it('stores a first version when a variable is created', function () {
    $variable = createVariable();

    expect($variable->key)->toBe('MAIL_PASSWORD')
        ->and($variable->versions()->count())->toBe(1)
        ->and($variable->currentVersion()->version)->toBe(1)
        ->and($variable->currentVersion()->author_id)->toBe($this->author->id);
});

it('never writes the plaintext to the database', function () {
    createVariable(value: 'super-secret-value');

    $row = VariableVersion::sole();

    expect($row->ciphertext)->not->toContain('super-secret-value')
        ->and($row->getAttributes())->not->toHaveKey('value');
});

it('reveals the plaintext on demand', function () {
    $variable = createVariable(value: 'postgres://user:pw@host/db');

    expect($variable->currentVersion()->reveal())->toBe('postgres://user:pw@host/db');
});

it('keeps the secret out of the model\'s array and json form', function () {
    $version = createVariable(value: 'super-secret-value')->currentVersion();

    expect($version->toArray())->not->toHaveKey('ciphertext')
        ->and($version->toArray())->not->toHaveKey('checksum')
        ->and(json_encode($version))->not->toContain('super-secret-value');
});

it('creates a new version when the value changes', function () {
    $variable = createVariable(value: 'first');

    app(UpdateVariableValue::class)->handle($variable, 'second', $this->author, 'roteren');

    expect($variable->versions()->count())->toBe(2)
        ->and($variable->currentVersion()->version)->toBe(2)
        ->and($variable->currentVersion()->reveal())->toBe('second')
        ->and($variable->currentVersion()->note)->toBe('roteren');
});

it('keeps the old version readable after an update', function () {
    $variable = createVariable(value: 'first');
    app(UpdateVariableValue::class)->handle($variable, 'second', $this->author);

    $first = $variable->versions()->where('version', 1)->sole();

    expect($first->reveal())->toBe('first');
});

it('does not create a new version when the value is unchanged', function () {
    $variable = createVariable(value: 'same');

    app(UpdateVariableValue::class)->handle($variable, 'same', $this->author);

    expect($variable->versions()->count())->toBe(1);
});

it('detects an unchanged value without decrypting anything', function () {
    $variable = createVariable(value: 'same');
    $checksum = $variable->currentVersion()->checksum;

    app(UpdateVariableValue::class)->handle($variable, 'changed', $this->author);

    expect($variable->currentVersion()->checksum)->not->toBe($checksum);
});

it('gives the same value a different checksum in a different team', function () {
    $mine = createVariable(value: 'shared-value');

    $otherTeam = Team::factory()->create();
    $theirs = app(CreateVariable::class)->handle($otherTeam, 'MAIL_PASSWORD', 'shared-value', $this->author);

    expect($theirs->currentVersion()->checksum)
        ->not->toBe($mine->currentVersion()->checksum);
});

it('records which team key version encrypted each value', function () {
    $variable = createVariable();

    expect($variable->currentVersion()->team_key_version)
        ->toBe($this->team->currentKey()->version);
});

it('allows two variables with the same key in one team', function () {
    createVariable(key: 'DATABASE_URL', value: 'shop');
    createVariable(key: 'DATABASE_URL', value: 'blog');

    expect(Variable::where('key', 'DATABASE_URL')->count())->toBe(2);
});

it('reports whether a variable is shared based on its assignments', function () {
    $variable = createVariable();

    expect($variable->isShared())->toBeFalse();
});
