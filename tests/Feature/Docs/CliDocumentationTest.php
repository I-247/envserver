<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('shows the CLI documentation to guests by default', function () {
    config(['app.url' => 'https://envserver.example.com']);

    $this->get(route('docs.cli'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('docs/cli')
            ->where('server', 'https://envserver.example.com')
        );
});

it('sends guests to the login page when the documentation is not public', function () {
    config(['envserver.public_cli_docs' => false]);

    $this->get(route('docs.cli'))->assertRedirect(route('login'));
});

it('returns guests to the documentation after logging in', function () {
    config(['envserver.public_cli_docs' => false]);

    $this->get(route('docs.cli'));

    expect(session('url.intended'))->toBe(route('docs.cli'));
});

it('shows the CLI documentation to signed in users when it is not public', function () {
    config(['envserver.public_cli_docs' => false]);

    $this->actingAs(User::factory()->create())
        ->get(route('docs.cli'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('docs/cli'));
});
