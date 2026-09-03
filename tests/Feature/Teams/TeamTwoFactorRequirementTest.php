<?php

use App\Enums\AuditAction;
use App\Enums\TeamRole;
use App\Models\AuditEvent;
use App\Models\User;

/**
 * Enrol the user in an authenticator app.
 */
function enrolInTotp(User $user): User
{
    $user->forceFill([
        'two_factor_secret' => encrypt('SECRET'),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $user;
}

/**
 * Register a passkey for the user.
 */
function registerPasskey(User $user, string $credentialId = 'credential-1'): User
{
    $user->passkeys()->create([
        'name' => 'YubiKey',
        'credential_id' => $credentialId,
        'credential' => ['id' => $credentialId],
    ]);

    return $user->refresh();
}

beforeEach(function () {
    $this->user = actingAsTeamMember(TeamRole::Owner);
    $this->team = $this->user->currentTeam;
});

it('turns the requirement on for an owner who is enrolled', function () {
    enrolInTotp($this->user);

    $this->put(route('teams.two-factor.update', $this->team->slug), ['two_factor_required' => true, 'password' => 'password'])
        ->assertRedirect(route('teams.edit', $this->team->slug))
        ->assertSessionHasNoErrors();

    expect($this->team->fresh()->two_factor_required)->toBeTrue();
});

it('accepts a passkey as the enrolment that unlocks the switch', function () {
    registerPasskey($this->user);

    $this->put(route('teams.two-factor.update', $this->team->slug), ['two_factor_required' => true, 'password' => 'password'])
        ->assertSessionHasNoErrors();

    expect($this->team->fresh()->two_factor_required)->toBeTrue();
});

it('refuses to turn the requirement on while the actor has no second factor', function () {
    $this->put(route('teams.two-factor.update', $this->team->slug), ['two_factor_required' => true, 'password' => 'password'])
        ->assertSessionHasErrors('two_factor_required');

    expect($this->team->fresh()->two_factor_required)->toBeFalse();
});

it('turns the requirement off without demanding a second factor', function () {
    $this->team->update(['two_factor_required' => true, 'password' => 'password']);

    $this->put(route('teams.two-factor.update', $this->team->slug), ['two_factor_required' => false, 'password' => 'password'])
        ->assertSessionHasNoErrors();

    expect($this->team->fresh()->two_factor_required)->toBeFalse();
});

it('lets an admin change the requirement but not a member', function (TeamRole $role, bool $allowed) {
    $user = actingAsTeamMember($role, $this->team);
    enrolInTotp($user);

    $response = $this->put(route('teams.two-factor.update', $this->team->slug), ['two_factor_required' => true, 'password' => 'password']);

    $allowed
        ? $response->assertSessionHasNoErrors()
        : $response->assertForbidden();

    expect($this->team->fresh()->two_factor_required)->toBe($allowed);
})->with([
    'admin' => [TeamRole::Admin, true],
    'member' => [TeamRole::Member, false],
    'viewer' => [TeamRole::Viewer, false],
]);

it('records the change in the audit trail', function () {
    enrolInTotp($this->user);
    actingAsTeamMember(TeamRole::Member, $this->team);
    $this->actingAs($this->user);

    $this->put(route('teams.two-factor.update', $this->team->slug), ['two_factor_required' => true, 'password' => 'password']);

    $event = AuditEvent::where('action', AuditAction::TeamTwoFactorRequirementUpdated)->sole();

    expect($event->team_id)->toBe($this->team->id)
        ->and($event->actor_id)->toBe($this->user->id)
        ->and($event->metadata)->toBe([
            'required' => true,
            'members_without_second_factor' => 1,
        ]);
});

it('records nothing when the requirement did not actually change', function () {
    $this->put(route('teams.two-factor.update', $this->team->slug), ['two_factor_required' => false, 'password' => 'password']);

    expect(AuditEvent::where('action', AuditAction::TeamTwoFactorRequirementUpdated)->count())->toBe(0);
});

it('sends a member without a second factor to their security settings', function () {
    $this->team->update(['two_factor_required' => true, 'password' => 'password']);

    $this->get(route('dashboard', ['current_team' => $this->team->slug]))
        ->assertRedirect(route('security.edit'));
});

it('lets an enrolled member through', function () {
    $this->team->update(['two_factor_required' => true, 'password' => 'password']);
    enrolInTotp($this->user);

    $this->get(route('dashboard', ['current_team' => $this->team->slug]))
        ->assertOk();
});

it('lets a member with only a passkey through', function () {
    $this->team->update(['two_factor_required' => true, 'password' => 'password']);
    registerPasskey($this->user);

    $this->get(route('dashboard', ['current_team' => $this->team->slug]))
        ->assertOk();
});

it('keeps the team settings page reachable so the switch can be undone', function () {
    $this->team->update(['two_factor_required' => true, 'password' => 'password']);

    $this->get(route('teams.edit', $this->team->slug))->assertOk();

    $this->put(route('teams.two-factor.update', $this->team->slug), ['two_factor_required' => false, 'password' => 'password'])
        ->assertSessionHasNoErrors();

    expect($this->team->fresh()->two_factor_required)->toBeFalse();
});

it('leaves a team that does not require a second factor alone', function () {
    $this->get(route('dashboard', ['current_team' => $this->team->slug]))->assertOk();
});

it('refuses a change confirmed with the wrong password', function () {
    enrolInTotp($this->user);

    $this->put(route('teams.two-factor.update', $this->team->slug), [
        'two_factor_required' => true,
        'password' => 'not-my-password',
    ])->assertSessionHasErrors('password');

    expect($this->team->fresh()->two_factor_required)->toBeFalse();
});

it('refuses a change with no password at all', function () {
    enrolInTotp($this->user);

    $this->put(route('teams.two-factor.update', $this->team->slug), ['two_factor_required' => true])
        ->assertSessionHasErrors('password');

    expect($this->team->fresh()->two_factor_required)->toBeFalse();
});

it('asks for the password again when lifting the requirement', function () {
    $this->team->update(['two_factor_required' => true]);

    $this->put(route('teams.two-factor.update', $this->team->slug), ['two_factor_required' => false])
        ->assertSessionHasErrors('password');

    expect($this->team->fresh()->two_factor_required)->toBeTrue();
});
