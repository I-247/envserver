<?php

use App\Enums\TeamRole;

it('lets everyone reach the sign in page while no allow list is configured', function () {
    config()->set('kluis.ip_allowlist', []);

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
        ->get('/login')
        ->assertOk();
});

it('blocks the sign in page from an address outside the allow list', function () {
    config()->set('kluis.ip_allowlist', ['203.0.113.0/24']);

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
        ->get('/login')
        ->assertForbidden();
});

it('lets an address inside the allow list sign in', function () {
    config()->set('kluis.ip_allowlist', ['203.0.113.0/24']);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->get('/login')
        ->assertOk();
});

it('blocks a session that was already signed in from elsewhere', function () {
    $user = actingAsTeamMember(TeamRole::Owner);

    config()->set('kluis.ip_allowlist', ['203.0.113.0/24']);

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
        ->get(route('dashboard', ['current_team' => $user->currentTeam->slug]))
        ->assertForbidden();
});

it('ignores a forwarded address while no proxy is trusted', function () {
    config()->set('kluis.trusted_proxies', []);
    config()->set('kluis.ip_allowlist', ['203.0.113.0/24']);

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
        ->get('/login', ['X-Forwarded-For' => '203.0.113.9'])
        ->assertForbidden();
});

it('reads the forwarded address once the proxy is trusted', function () {
    config()->set('kluis.trusted_proxies', ['198.51.100.7']);
    config()->set('kluis.ip_allowlist', ['203.0.113.0/24']);

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
        ->get('/login', ['X-Forwarded-For' => '203.0.113.9'])
        ->assertOk();

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
        ->get('/login', ['X-Forwarded-For' => '198.51.100.20'])
        ->assertForbidden();
});
