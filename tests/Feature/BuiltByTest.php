<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests can view the built by page', function () {
    $this->get(route('built-by'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('built-by'));
});

test('signed in users can view the built by page', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('built-by'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('built-by'));
});
