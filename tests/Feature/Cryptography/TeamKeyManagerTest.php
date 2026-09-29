<?php

use App\Actions\Variables\CreateVariable;
use App\Contracts\SecretCipher;
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
        ->and($stored)->toStartWith('v2.');
});

it('still unwraps a data key after the master key was rotated', function () {
    config(['envserver.master_key' => masterKey('old'), 'envserver.previous_master_keys' => []]);

    $team = Team::factory()->create();
    $original = app(TeamKeyManager::class)->dataKeyFor($team);

    config([
        'envserver.master_key' => masterKey('new'),
        'envserver.previous_master_keys' => [masterKey('old')],
    ]);

    expect(app(TeamKeyManager::class)->dataKeyFor($team->fresh()))->toBe($original);
});

it('refuses to guess when no master key can unwrap the data key', function () {
    config(['envserver.master_key' => masterKey('old'), 'envserver.previous_master_keys' => []]);

    $team = Team::factory()->create();
    app(TeamKeyManager::class)->dataKeyFor($team);

    config(['envserver.master_key' => masterKey('unrelated'), 'envserver.previous_master_keys' => []]);

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

it('does not decrypt a value that was copied into another variable', function () {
    $team = Team::factory()->create();

    $password = app(CreateVariable::class)->handle($team, 'DB_PASSWORD', 'the-real-secret');
    $harmless = app(CreateVariable::class)->handle($team, 'APP_NAME', 'Shop');

    $harmless->currentVersion()->forceFill(['ciphertext' => $password->currentVersion()->ciphertext])->saveQuietly();

    expect(fn () => $harmless->fresh()->currentVersion()->reveal())->toThrow(DecryptionFailed::class);
});

it('reads a value with the key version it was written with', function () {
    $team = Team::factory()->create();
    $variable = app(CreateVariable::class)->handle($team, 'API_KEY', 'written-under-v1');

    $team->currentKey()->forceFill(['retired_at' => now()])->save();
    $manager = app(TeamKeyManager::class);
    $manager->provision($team);

    expect($team->currentKey()->version)->toBe(2)
        ->and($variable->fresh()->currentVersion()->reveal())->toBe('written-under-v1');
});

it('provisions a single key when asked twice', function () {
    $team = Team::factory()->create();
    $manager = app(TeamKeyManager::class);

    $first = $manager->provision($team);
    $second = $manager->provision($team);

    expect($second->id)->toBe($first->id)
        ->and($team->keys()->count())->toBe(1);
});

it('re-wraps retired keys too, so the old master key can really go', function () {
    config(['envserver.master_key' => masterKey('old'), 'envserver.previous_master_keys' => []]);

    $team = Team::factory()->create();
    $variable = app(CreateVariable::class)->handle($team, 'API_KEY', 'kept');

    $team->currentKey()->forceFill(['retired_at' => now()])->save();
    app(TeamKeyManager::class)->provision($team);

    config(['envserver.master_key' => masterKey('new'), 'envserver.previous_master_keys' => [masterKey('old')]]);
    app()->forgetInstance(TeamKeyManager::class);
    app(TeamKeyManager::class)->rewrap($team);

    config(['envserver.previous_master_keys' => []]);
    app()->forgetInstance(TeamKeyManager::class);

    expect($variable->fresh()->currentVersion()->reveal())->toBe('kept');
});

it('binds a wrapped data key to its team and version', function () {
    $a = Team::factory()->create();
    $b = Team::factory()->create();
    $manager = app(TeamKeyManager::class);
    $manager->dataKeyFor($a);
    $manager->dataKeyFor($b);

    $b->currentKey()->forceFill(['wrapped_key' => $a->currentKey()->wrapped_key])->save();
    app()->forgetInstance(TeamKeyManager::class);

    expect(fn () => app(TeamKeyManager::class)->dataKeyFor($b->fresh()))->toThrow(DecryptionFailed::class);
});

it('moves legacy payloads onto the bound scheme without changing a value', function () {
    $team = Team::factory()->create();
    $variable = app(CreateVariable::class)->handle($team, 'DB_PASSWORD', 'p$ssw0rd');
    $version = $variable->currentVersion();

    $dataKey = app(TeamKeyManager::class)->dataKeyFor($team);
    $nonce = random_bytes(12);
    $tag = '';
    $raw = openssl_encrypt('p$ssw0rd', 'aes-256-gcm', $dataKey, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    $legacy = implode('.', ['v1', base64_encode($nonce), base64_encode($tag), base64_encode($raw)]);
    $version->forceFill(['ciphertext' => $legacy])->saveQuietly();

    $this->artisan('envserver:upgrade-encryption')->assertSuccessful();

    $upgraded = $version->fresh();

    expect(app(SecretCipher::class)->isLegacy($upgraded->ciphertext))->toBeFalse()
        ->and($upgraded->ciphertext)->toStartWith('v2.')
        ->and($upgraded->version)->toBe($version->version)
        ->and($upgraded->reveal())->toBe('p$ssw0rd')
        ->and($team->currentKey()->algorithm)->toBe('v2');
});
