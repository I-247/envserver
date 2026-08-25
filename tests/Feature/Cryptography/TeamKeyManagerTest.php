<?php

use App\Cryptography\TeamKeyManager;
use App\Exceptions\DecryptionFailed;
use App\Models\Team;
use App\Models\TeamKey;

function masterKey(string $seed): string
{
    return 'base64:'.base64_encode(str_pad($seed, 32, $seed));
}

it('provisions a data key the first time a team needs one', function () {
    $team = Team::factory()->create();

    $key = app(TeamKeyManager::class)->dataKeyFor($team);

    expect(strlen($key))->toBe(32)
        ->and($team->keys()->count())->toBe(1)
        ->and($team->currentKey()->version)->toBe(1);
});

it('reuses the existing data key on later calls', function () {
    $team = Team::factory()->create();
    $manager = app(TeamKeyManager::class);

    $first = $manager->dataKeyFor($team);
    $second = $manager->dataKeyFor($team->fresh());

    expect($second)->toBe($first)
        ->and(TeamKey::count())->toBe(1);
});

it('gives every team its own data key', function () {
    $manager = app(TeamKeyManager::class);

    $a = $manager->dataKeyFor(Team::factory()->create());
    $b = $manager->dataKeyFor(Team::factory()->create());

    expect($a)->not->toBe($b);
});

it('never stores the raw data key', function () {
    $team = Team::factory()->create();

    $key = app(TeamKeyManager::class)->dataKeyFor($team);
    $stored = $team->currentKey()->wrapped_key;

    expect($stored)->not->toContain($key)
        ->and($stored)->not->toContain(base64_encode($key))
        ->and($stored)->toStartWith('v1.');
});

it('still unwraps a data key after the master key was rotated', function () {
    config(['kluis.master_key' => masterKey('old'), 'kluis.previous_master_keys' => []]);

    $team = Team::factory()->create();
    $original = app(TeamKeyManager::class)->dataKeyFor($team);

    config([
        'kluis.master_key' => masterKey('new'),
        'kluis.previous_master_keys' => [masterKey('old')],
    ]);

    expect(app(TeamKeyManager::class)->dataKeyFor($team->fresh()))->toBe($original);
});

it('refuses to guess when no master key can unwrap the data key', function () {
    config(['kluis.master_key' => masterKey('old'), 'kluis.previous_master_keys' => []]);

    $team = Team::factory()->create();
    app(TeamKeyManager::class)->dataKeyFor($team);

    config(['kluis.master_key' => masterKey('unrelated'), 'kluis.previous_master_keys' => []]);

    expect(fn () => app(TeamKeyManager::class)->dataKeyFor($team->fresh()))
        ->toThrow(DecryptionFailed::class);
});

it('encrypts and decrypts a value for a team', function () {
    $team = Team::factory()->create();
    $manager = app(TeamKeyManager::class);

    $payload = $manager->encryptFor($team, 'postgres://user:secret@host/db');

    expect($payload)->not->toContain('secret')
        ->and($manager->decryptFor($team, $payload))->toBe('postgres://user:secret@host/db');
});

it('cannot decrypt a value that belongs to another team', function () {
    $manager = app(TeamKeyManager::class);
    $payload = $manager->encryptFor(Team::factory()->create(), 'hunter2');

    expect(fn () => $manager->decryptFor(Team::factory()->create(), $payload))
        ->toThrow(DecryptionFailed::class);
});
