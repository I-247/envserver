<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Passport\Client;

function deviceClient(): Client
{
    Artisan::call('kluis:cli-client', ['--env-file' => base_path('.env.kluis-device-test')]);

    return Client::sole();
}

beforeEach(function () {
    file_put_contents(base_path('.env.kluis-device-test'), "KLUIS_CLI_CLIENT_ID=\n");
});

afterEach(function () {
    @unlink(base_path('.env.kluis-device-test'));
});

it('publishes the CLI client id so the CLI only needs a server URL', function () {
    $client = deviceClient();
    config(['kluis.cli_client_id' => $client->getKey()]);

    $this->getJson('/api/v1/cli')
        ->assertOk()
        ->assertJsonPath('data.client_id', (string) $client->getKey())
        ->assertJsonPath('data.device_code_endpoint', url('/oauth/device/code'))
        ->assertJsonPath('data.token_endpoint', url('/oauth/token'))
        ->assertJsonPath('data.scopes.0', 'projects:read');
});

it('says so plainly when no CLI client has been created yet', function () {
    config(['kluis.cli_client_id' => null]);

    $this->getJson('/api/v1/cli')->assertStatus(503);
});

it('hands out a device code the CLI can poll on', function () {
    $client = deviceClient();

    $response = $this->post('/oauth/device/code', [
        'client_id' => $client->getKey(),
        'scope' => 'projects:read env:read',
    ])->assertOk();

    expect($response->json())
        ->toHaveKeys(['device_code', 'user_code', 'verification_uri', 'interval', 'expires_in']);
});

it('shows the user code screen to a signed in user', function () {
    deviceClient();

    $this->actingAs(User::factory()->create())
        ->get('/oauth/device')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('auth/device/user-code'));
});

it('shows the code screen to anyone but demands a login to approve', function () {
    deviceClient();

    // The code form itself is harmless: entering a code proves nothing.
    $this->get('/oauth/device')->assertOk();

    // Approving is where an identity is needed.
    $this->get('/oauth/device/authorize?user_code=XXXX-XXXX')
        ->assertRedirect(route('login'));
});

it('refuses a device code request from an unknown client', function () {
    $this->post('/oauth/device/code', ['client_id' => '00000000-0000-0000-0000-000000000000'])
        ->assertStatus(401);
});
