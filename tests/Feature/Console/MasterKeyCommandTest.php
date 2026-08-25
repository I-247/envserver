<?php

use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->envPath = base_path('.env.kluis-test');
    File::put($this->envPath, "APP_NAME=Kluis\nKLUIS_MASTER_KEY=\n");
});

afterEach(function () {
    File::delete($this->envPath);
});

it('prints a usable key when asked to only show it', function () {
    $this->artisan('kluis:master-key --show')
        ->expectsOutputToContain('base64:')
        ->assertSuccessful();

    expect(File::get($this->envPath))->toContain('KLUIS_MASTER_KEY=');
});

it('writes a fresh key into the env file', function () {
    $this->artisan('kluis:master-key', ['--env-file' => $this->envPath])
        ->assertSuccessful();

    preg_match('/^KLUIS_MASTER_KEY=(.*)$/m', File::get($this->envPath), $matches);

    expect(strlen(base64_decode(mb_substr(trim($matches[1]), 7), strict: true)))->toBe(32);
});

it('refuses to overwrite an existing key without confirmation', function () {
    File::put($this->envPath, 'KLUIS_MASTER_KEY=base64:'.base64_encode(str_repeat('k', 32))."\n");

    $this->artisan('kluis:master-key', ['--env-file' => $this->envPath])
        ->expectsOutputToContain('already set')
        ->assertFailed();

    expect(File::get($this->envPath))->toContain(base64_encode(str_repeat('k', 32)));
});

it('moves the old key to the previous keys list when forced', function () {
    $old = 'base64:'.base64_encode(str_repeat('k', 32));
    File::put($this->envPath, "KLUIS_MASTER_KEY={$old}\nKLUIS_PREVIOUS_MASTER_KEYS=\n");

    $this->artisan('kluis:master-key', ['--env-file' => $this->envPath, '--force' => true])
        ->assertSuccessful();

    $contents = File::get($this->envPath);

    expect($contents)->toContain("KLUIS_PREVIOUS_MASTER_KEYS={$old}")
        ->and($contents)->not->toContain("KLUIS_MASTER_KEY={$old}");
});
