<?php

use App\Cryptography\AesGcmSecretCipher;
use App\Exceptions\DecryptionFailed;

beforeEach(function () {
    $this->cipher = new AesGcmSecretCipher;
    $this->key = random_bytes(32);
});

it('round trips a value', function (string $plaintext) {
    $payload = $this->cipher->encrypt($plaintext, $this->key);

    expect($this->cipher->decrypt($payload, $this->key))->toBe($plaintext);
})->with([
    'simple' => 'hunter2',
    'empty' => '',
    'unicode' => 'wachtwoord-mét-ünicode-🔐',
    'multiline' => "line one\nline two\n",
    'long' => str_repeat('a', 10_000),
    'env-ish' => 'postgres://user:p@ss w0rd!@host:5432/db?sslmode=require',
]);

it('never stores the plaintext in the payload', function () {
    $payload = $this->cipher->encrypt('super-secret-value', $this->key);

    expect($payload)->not->toContain('super-secret-value');
});

it('produces a different payload every time so equal values are not detectable', function () {
    $first = $this->cipher->encrypt('same', $this->key);
    $second = $this->cipher->encrypt('same', $this->key);

    expect($first)->not->toBe($second);
});

it('tags the payload with the scheme version', function () {
    expect($this->cipher->encrypt('x', $this->key))->toStartWith('v1.');
});

it('refuses a payload that was tampered with', function () {
    $payload = $this->cipher->encrypt('hunter2', $this->key);

    [$version, $nonce, $tag, $ciphertext] = explode('.', $payload);
    $flipped = base64_decode($ciphertext);
    $flipped[0] = $flipped[0] === 'a' ? 'b' : 'a';

    $tampered = implode('.', [$version, $nonce, $tag, base64_encode($flipped)]);

    expect(fn () => $this->cipher->decrypt($tampered, $this->key))
        ->toThrow(DecryptionFailed::class);
});

it('refuses a payload signed with a different key', function () {
    $payload = $this->cipher->encrypt('hunter2', $this->key);

    expect(fn () => $this->cipher->decrypt($payload, random_bytes(32)))
        ->toThrow(DecryptionFailed::class);
});

it('refuses a payload of an unknown scheme', function () {
    $payload = $this->cipher->encrypt('hunter2', $this->key);
    $future = 'v9'.mb_substr($payload, 2);

    expect(fn () => $this->cipher->decrypt($future, $this->key))
        ->toThrow(DecryptionFailed::class);
});

it('refuses a malformed payload', function (string $payload) {
    expect(fn () => $this->cipher->decrypt($payload, $this->key))
        ->toThrow(DecryptionFailed::class);
})->with([
    'empty' => '',
    'no separators' => 'v1',
    'too few parts' => 'v1.aaaa.bbbb',
    'not base64' => 'v1.@@@@.@@@@.@@@@',
]);

it('rejects a key that is not 256 bits', function () {
    expect(fn () => $this->cipher->encrypt('x', random_bytes(16)))
        ->toThrow(InvalidArgumentException::class);
});
