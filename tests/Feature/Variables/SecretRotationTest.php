<?php

use App\Actions\Teams\SetTeamRotationPolicy;
use App\Actions\Variables\CreateVariable;
use App\Actions\Variables\ReviewSecretAge;
use App\Data\SecretAge;
use App\Enums\AuditAction;
use App\Enums\TeamRole;
use App\Models\AuditEvent;
use App\Models\Team;
use App\Models\Variable;

beforeEach(function () {
    $this->team = Team::factory()->create(['slug' => 'acme']);
});

function agedVariable(string $key, int $daysOld, ?int $rotateAfterDays = null): Variable
{
    $variable = test()->travelTo(now()->subDays($daysOld), fn () => app(CreateVariable::class)->handle(
        test()->team,
        $key,
        'value-for-'.$key,
    ));

    if ($rotateAfterDays !== null) {
        $variable->update(['rotate_after_days' => $rotateAfterDays]);
    }

    return $variable->fresh();
}

function overdueKeys(): array
{
    return app(ReviewSecretAge::class)
        ->overdue(test()->team->fresh())
        ->map(fn (SecretAge $age) => $age->variable->key)
        ->all();
}

it('reports nothing while the team has no policy', function () {
    agedVariable('OLD_KEY', 400);

    expect(overdueKeys())->toBe([]);
});

it('flags a secret older than the team interval', function () {
    $this->team->update(['default_rotate_after_days' => 90]);

    agedVariable('OLD_KEY', 120);
    agedVariable('FRESH_KEY', 10);

    expect(overdueKeys())->toBe(['OLD_KEY']);
});

it('lets a variable set its own interval over the team default', function () {
    $this->team->update(['default_rotate_after_days' => 90]);

    agedVariable('BUILD_NUMBER', 120, rotateAfterDays: 3650);
    agedVariable('API_KEY', 40, rotateAfterDays: 30);

    expect(overdueKeys())->toBe(['API_KEY']);
});

it('puts the most overdue secret first', function () {
    $this->team->update(['default_rotate_after_days' => 30]);

    agedVariable('SLIGHTLY_LATE', 40);
    agedVariable('VERY_LATE', 400);

    expect(overdueKeys())->toBe(['VERY_LATE', 'SLIGHTLY_LATE']);
});

it('counts the days a rotation is late', function () {
    $this->team->update(['default_rotate_after_days' => 30]);

    agedVariable('LATE', 45);

    $age = app(ReviewSecretAge::class)->overdue($this->team->fresh())->sole();

    expect($age->overdueByDays())->toBe(15)
        ->and($age->ageInDays())->toBe(45)
        ->and($age->intervalDays)->toBe(30);
});

it('records turning the policy off, not just turning it on', function () {
    $actor = actingAsTeamMember(TeamRole::Owner, $this->team);

    app(SetTeamRotationPolicy::class)->handle($this->team, 90, $actor);
    app(SetTeamRotationPolicy::class)->handle($this->team->fresh(), null, $actor);

    $events = AuditEvent::query()
        ->where('action', AuditAction::TeamRotationPolicyUpdated->value)
        ->orderBy('id')
        ->get();

    expect($events)->toHaveCount(2)
        ->and($events->first()->metadata['days'])->toBe(90)
        ->and($events->last()->metadata['days'])->toBeNull();
});

it('does not record a policy that did not change', function () {
    $this->team->update(['default_rotate_after_days' => 90]);

    app(SetTeamRotationPolicy::class)->handle($this->team, 90);

    expect(AuditEvent::query()->where('action', AuditAction::TeamRotationPolicyUpdated->value)->count())->toBe(0);
});

it('saves the policy from the team settings page', function () {
    actingAsTeamMember(TeamRole::Owner, $this->team);

    $this->put('/settings/teams/acme/rotation-policy', ['default_rotate_after_days' => 60])
        ->assertRedirect('/settings/teams/acme');

    expect($this->team->fresh()->default_rotate_after_days)->toBe(60);

    $this->put('/settings/teams/acme/rotation-policy', ['default_rotate_after_days' => null])
        ->assertRedirect('/settings/teams/acme');

    expect($this->team->fresh()->default_rotate_after_days)->toBeNull();
});

it('refuses an interval outside the allowed range', function () {
    actingAsTeamMember(TeamRole::Owner, $this->team);

    $this->put('/settings/teams/acme/rotation-policy', ['default_rotate_after_days' => 0])
        ->assertSessionHasErrors('default_rotate_after_days');

    $this->put('/settings/teams/acme/rotation-policy', ['default_rotate_after_days' => 4000])
        ->assertSessionHasErrors('default_rotate_after_days');
});

it('refuses a member who may not change team settings', function () {
    actingAsTeamMember(TeamRole::Member, $this->team);

    $this->put('/settings/teams/acme/rotation-policy', ['default_rotate_after_days' => 60])
        ->assertForbidden();
});
