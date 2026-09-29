<?php

use App\Models\User;
use Illuminate\Support\Facades\Gate;

it('lets only verified, allowed addresses into Horizon', function () {
    config(['horizon.allowed_emails' => ['ops@example.com']]);

    $verified = User::factory()->create(['email' => 'ops@example.com']);
    $squatter = User::factory()->unverified()->make(['email' => 'ops@example.com']);
    $stranger = User::factory()->create();

    expect(Gate::forUser($verified)->allows('viewHorizon'))->toBeTrue()
        ->and(Gate::forUser($squatter)->allows('viewHorizon'))->toBeFalse()
        ->and(Gate::forUser($stranger)->allows('viewHorizon'))->toBeFalse();
});
