<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Unit');

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
*/

/**
 * Create a team with a single member in the given role, and act as them.
 */
function actingAsTeamMember(TeamRole $role = TeamRole::Owner, ?Team $team = null): User
{
    $user = User::factory()->create();
    $team ??= Team::factory()->create();

    $team->members()->attach($user, ['role' => $role->value]);

    $user->forceFill(['current_team_id' => $team->id])->save();

    test()->actingAs($user);

    return $user->refresh();
}
