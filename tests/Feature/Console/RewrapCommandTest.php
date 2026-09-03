<?php

use App\Cryptography\TeamKeyManager;
use App\Models\Team;

function rewrapKey(string $seed): string
{
    return 'base64:'.base64_encode(str_pad($seed, 32, $seed));
}

it('re-wraps every team key with the current master key', function () {
    config(['envserver.master_key' => rewrapKey('old'), 'envserver.previous_master_keys' => []]);

    $teams = Team::factory()->count(3)->create();
    $keys = $teams->map(fn (Team $team) => app(TeamKeyManager::class)->dataKeyFor($team));
    $wrapped = $teams->map(fn (Team $team) => $team->currentKey()->wrapped_key);

    config([
        'envserver.master_key' => rewrapKey('new'),
        'envserver.previous_master_keys' => [rewrapKey('old')],
    ]);

    $this->artisan('envserver:rewrap')
        ->expectsOutputToContain('3')
        ->assertSuccessful();

    // The stored form changed, the data key behind it did not: nothing had to
    // be re-encrypted, which is the whole point of wrapping.
    $teams->each(function (Team $team, int $index) use ($keys, $wrapped) {
        expect($team->fresh()->currentKey()->wrapped_key)->not->toBe($wrapped[$index]);
        expect(app(TeamKeyManager::class)->dataKeyFor($team->fresh()))->toBe($keys[$index]);
    });
});

it('leaves the secrets readable after the old master key is dropped', function () {
    config(['envserver.master_key' => rewrapKey('old'), 'envserver.previous_master_keys' => []]);

    $team = Team::factory()->create();
    $payload = app(TeamKeyManager::class)->encryptFor($team, 'hunter2');

    config([
        'envserver.master_key' => rewrapKey('new'),
        'envserver.previous_master_keys' => [rewrapKey('old')],
    ]);

    $this->artisan('envserver:rewrap')->assertSuccessful();

    config(['envserver.previous_master_keys' => []]);

    expect(app(TeamKeyManager::class)->decryptFor($team->fresh(), $payload))->toBe('hunter2');
});

it('reports a team whose key no master key can open, without stopping', function () {
    config(['envserver.master_key' => rewrapKey('old'), 'envserver.previous_master_keys' => []]);

    $healthy = Team::factory()->create();
    $broken = Team::factory()->create();

    app(TeamKeyManager::class)->dataKeyFor($healthy);
    app(TeamKeyManager::class)->dataKeyFor($broken);

    $broken->currentKey()->forceFill(['wrapped_key' => 'v1.aaaa.bbbb.cccc'])->save();

    config([
        'envserver.master_key' => rewrapKey('new'),
        'envserver.previous_master_keys' => [rewrapKey('old')],
    ]);

    $this->artisan('envserver:rewrap')
        ->expectsOutputToContain('1 could not be re-wrapped')
        ->assertFailed();

    // The team that could be saved still was.
    expect(strlen(app(TeamKeyManager::class)->dataKeyFor($healthy->fresh())))->toBe(32);
});

it('does nothing when there are no teams', function () {
    $this->artisan('envserver:rewrap')->assertSuccessful();
});
