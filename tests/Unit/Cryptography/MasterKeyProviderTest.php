<?php

use App\Cryptography\MasterKeyProvider;
use App\Exceptions\MasterKeyMissing;
use Illuminate\Support\Facades\Log;

function base64Key(string $seed = 'a'): string
{
    return 'base64:'.base64_encode(str_pad($seed, 32, $seed));
}

it('decodes the configured master key to raw bytes', function () {
    config(['envserver.master_key' => base64Key('k')]);

    expect(new MasterKeyProvider)->current()->toHaveLength(32);
});

it('accepts a raw 32 byte key without the base64 prefix', function () {
    config(['envserver.master_key' => str_repeat('k', 32)]);

    expect((new MasterKeyProvider)->current())->toBe(str_repeat('k', 32));
});

it('fails loudly when no master key is configured', function () {
    config(['envserver.master_key' => null]);

    expect(fn () => (new MasterKeyProvider)->current())
        ->toThrow(MasterKeyMissing::class);
});

it('fails loudly when the master key is the wrong length', function () {
    config(['envserver.master_key' => 'base64:'.base64_encode('too-short')]);

    expect(fn () => (new MasterKeyProvider)->current())
        ->toThrow(MasterKeyMissing::class);
});

it('offers the current key first and previous keys after it', function () {
    config([
        'envserver.master_key' => base64Key('n'),
        'envserver.previous_master_keys' => [base64Key('o'), base64Key('p')],
    ]);

    $provider = new MasterKeyProvider;

    expect($provider->all())->toHaveCount(3)
        ->and($provider->all()[0])->toBe($provider->current());
});

it('ignores previous keys that are unusable rather than breaking unwrapping', function () {
    config([
        'envserver.master_key' => base64Key('n'),
        'envserver.previous_master_keys' => ['nonsense', base64Key('o')],
    ]);

    expect((new MasterKeyProvider)->all())->toHaveCount(2);
});

it('names an unusable retired key by position, never by value', function () {
    Log::spy();

    config([
        'envserver.master_key' => base64Key('k'),
        'envserver.previous_master_keys' => [base64Key('o'), 'base64:typo-in-this-one'],
    ]);

    expect((new MasterKeyProvider)->all())->toHaveCount(2);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message) => str_contains($message, '#2') && ! str_contains($message, 'typo'))
        ->once();
});
