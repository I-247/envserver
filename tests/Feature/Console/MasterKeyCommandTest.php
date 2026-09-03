<?php

use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->envPath = base_path('.env.envclient-test');
    File::put($this->envPath, "APP_NAME=Envserver\nENVSERVER_MASTER_KEY=\n");
});

afterEach(function () {
    File::delete($this->envPath);
});

it('prints a usable key when asked to only show it', function () {
    $this->artisan('envserver:master-key --show')
        ->expectsOutputToContain('base64:')
        ->assertSuccessful();

    expect(File::get($this->envPath))->toContain('ENVSERVER_MASTER_KEY=');
});

it('writes a fresh key into the env file', function () {
    $this->artisan('envserver:master-key', ['--env-file' => $this->envPath])
        ->assertSuccessful();

    preg_match('/^ENVSERVER_MASTER_KEY=(.*)$/m', File::get($this->envPath), $matches);

    expect(strlen(base64_decode(mb_substr(trim($matches[1]), 7), strict: true)))->toBe(32);
});

it('refuses to overwrite an existing key without confirmation', function () {
    File::put($this->envPath, 'ENVSERVER_MASTER_KEY=base64:'.base64_encode(str_repeat('k', 32))."\n");

    $this->artisan('envserver:master-key', ['--env-file' => $this->envPath])
        ->expectsOutputToContain('already set')
        ->assertFailed();

    expect(File::get($this->envPath))->toContain(base64_encode(str_repeat('k', 32)));
});

it('fails loudly when the env file cannot be written', function () {
    if (function_exists('posix_getuid') && posix_getuid() === 0) {
        $this->markTestSkipped('root ignores the read-only permission this test relies on.');
    }

    chmod($this->envPath, 0444);

    try {
        $this->artisan('envserver:master-key', ['--env-file' => $this->envPath])
            ->expectsOutputToContain('Could not write to')
            ->assertFailed();

        // The command must not claim success while nothing was written.
        expect(File::get($this->envPath))->not->toContain('base64:');
    } finally {
        chmod($this->envPath, 0644);
    }
});

it('moves the old key to the previous keys list when forced', function () {
    $old = 'base64:'.base64_encode(str_repeat('k', 32));
    File::put($this->envPath, "ENVSERVER_MASTER_KEY={$old}\nENVSERVER_PREVIOUS_MASTER_KEYS=\n");

    $this->artisan('envserver:master-key', ['--env-file' => $this->envPath, '--force' => true])
        ->assertSuccessful();

    $contents = File::get($this->envPath);

    expect($contents)->toContain("ENVSERVER_PREVIOUS_MASTER_KEYS={$old}")
        ->and($contents)->not->toContain("ENVSERVER_MASTER_KEY={$old}");
});
