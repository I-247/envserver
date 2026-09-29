<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Support\HostResolver;
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

/**
 * Answer DNS lookups from a fixed table instead of the network.
 *
 * Any name not in the table resolves to nothing, like a name that does not exist.
 *
 * @param  array<string, list<string>>  $records
 */
function fakeDns(array $records = ['hooks.example.com' => ['93.184.215.14'], 'hooks.slack.com' => ['54.192.1.1']]): void
{
    app()->instance(HostResolver::class, new class($records) extends HostResolver
    {
        /**
         * @param  array<string, list<string>>  $records
         */
        public function __construct(private array $records) {}

        public function resolve(string $host): array
        {
            return $this->records[strtolower($host)] ?? [];
        }
    });
}
