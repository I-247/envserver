<?php

use App\Cryptography\MasterKeyProvider;
use App\Exceptions\MasterKeyMissing;

function base64Key(string $seed = 'a'): string
{
    return 'base64:'.base64_encode(str_pad($seed, 32, $seed));
}

it('decodes the configured master key to raw bytes', function () {
    config(['kluis.master_key' => base64Key('k')]);

    expect(new MasterKeyProvider)->current()->toHaveLength(32);
});

it('accepts a raw 32 byte key without the base64 prefix', function () {
    config(['kluis.master_key' => str_repeat('k', 32)]);

    expect((new MasterKeyProvider)->current())->toBe(str_repeat('k', 32));
});

it('fails loudly when no master key is configured', function () {
    config(['kluis.master_key' => null]);

    expect(fn () => (new MasterKeyProvider)->current())
        ->toThrow(MasterKeyMissing::class);
});

it('fails loudly when the master key is the wrong length', function () {
    config(['kluis.master_key' => 'base64:'.base64_encode('too-short')]);

    expect(fn () => (new MasterKeyProvider)->current())
        ->toThrow(MasterKeyMissing::class);
});

it('offers the current key first and previous keys after it', function () {
    config([
        'kluis.master_key' => base64Key('n'),
        'kluis.previous_master_keys' => [base64Key('o'), base64Key('p')],
    ]);

    $provider = new MasterKeyProvider;

    expect($provider->all())->toHaveCount(3)
        ->and($provider->all()[0])->toBe($provider->current());
});

it('ignores previous keys that are unusable rather than breaking unwrapping', function () {
    config([
        'kluis.master_key' => base64Key('n'),
        'kluis.previous_master_keys' => ['nonsense', base64Key('o')],
    ]);

    expect((new MasterKeyProvider)->all())->toHaveCount(2);
});
