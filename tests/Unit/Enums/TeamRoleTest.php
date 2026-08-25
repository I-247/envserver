<?php

use App\Enums\TeamPermission;
use App\Enums\TeamRole;

it('grants every permission to the owner', function () {
    foreach (TeamPermission::cases() as $permission) {
        expect(TeamRole::Owner->hasPermission($permission))->toBeTrue();
    }
});

it('lets admins run projects but not delete the team', function () {
    expect(TeamRole::Admin->hasPermission(TeamPermission::CreateProject))->toBeTrue()
        ->and(TeamRole::Admin->hasPermission(TeamPermission::DeleteProject))->toBeTrue()
        ->and(TeamRole::Admin->hasPermission(TeamPermission::ManageDeployToken))->toBeTrue()
        ->and(TeamRole::Admin->hasPermission(TeamPermission::DeleteTeam))->toBeFalse();
});

it('lets members work with variables but not with deploy tokens', function () {
    expect(TeamRole::Member->hasPermission(TeamPermission::ManageVariable))->toBeTrue()
        ->and(TeamRole::Member->hasPermission(TeamPermission::ViewSecretValue))->toBeTrue()
        ->and(TeamRole::Member->hasPermission(TeamPermission::PublishRelease))->toBeTrue()
        ->and(TeamRole::Member->hasPermission(TeamPermission::ManageDeployToken))->toBeFalse()
        ->and(TeamRole::Member->hasPermission(TeamPermission::DeleteProject))->toBeFalse();
});

it('gives viewers no permissions at all, not even revealing a secret', function () {
    expect(TeamRole::Viewer->permissions())->toBe([])
        ->and(TeamRole::Viewer->hasPermission(TeamPermission::ViewSecretValue))->toBeFalse();
});

it('ranks roles from viewer up to owner', function () {
    expect(TeamRole::Owner->isAtLeast(TeamRole::Admin))->toBeTrue()
        ->and(TeamRole::Admin->isAtLeast(TeamRole::Member))->toBeTrue()
        ->and(TeamRole::Member->isAtLeast(TeamRole::Viewer))->toBeTrue()
        ->and(TeamRole::Viewer->isAtLeast(TeamRole::Member))->toBeFalse();
});

it('offers every role except owner as assignable', function () {
    $values = array_column(TeamRole::assignable(), 'value');

    expect($values)->toBe(['admin', 'member', 'viewer']);
});
