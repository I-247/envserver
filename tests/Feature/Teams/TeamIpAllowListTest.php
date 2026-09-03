<?php

use App\Enums\AuditAction;
use App\Enums\TeamRole;
use App\Models\AuditEvent;

beforeEach(function () {
    $this->user = actingAsTeamMember(TeamRole::Owner);
    $this->team = $this->user->currentTeam;
});

it('stores nothing while the field is left empty', function () {
    $this->put(route('teams.ip-allowlist.update', $this->team->slug), ['ip_allowlist' => ''])
        ->assertRedirect(route('teams.edit', $this->team->slug));

    expect($this->team->fresh()->ip_allowlist)->toBeNull();
});

it('saves a list that includes the current address', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->put(route('teams.ip-allowlist.update', $this->team->slug), [
            'ip_allowlist' => "203.0.113.0/24\n198.51.100.7",
        ])
        ->assertSessionHasNoErrors();

    expect($this->team->fresh()->ip_allowlist)->toBe(['203.0.113.0/24', '198.51.100.7']);
});

it('refuses a list the current address is not on', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
        ->put(route('teams.ip-allowlist.update', $this->team->slug), ['ip_allowlist' => '203.0.113.0/24'])
        ->assertSessionHasErrors('ip_allowlist');

    expect($this->team->fresh()->ip_allowlist)->toBeNull();
});

it('refuses an entry that is not an address or range', function () {
    $this->put(route('teams.ip-allowlist.update', $this->team->slug), ['ip_allowlist' => 'nope'])
        ->assertSessionHasErrors('ip_allowlist');
});

it('records the change in the audit trail', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->put(route('teams.ip-allowlist.update', $this->team->slug), ['ip_allowlist' => '203.0.113.0/24']);

    $event = AuditEvent::where('action', AuditAction::TeamIpAllowListUpdated)->sole();

    expect($event->team_id)->toBe($this->team->id)
        ->and($event->actor_id)->toBe($this->user->id)
        ->and($event->metadata)->toBe(['from' => [], 'to' => ['203.0.113.0/24']]);
});

it('blocks the team dashboard from an address outside the list', function () {
    $this->team->update(['ip_allowlist' => ['203.0.113.0/24']]);

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
        ->get(route('dashboard', ['current_team' => $this->team->slug]))
        ->assertForbidden();

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->get(route('dashboard', ['current_team' => $this->team->slug]))
        ->assertOk();
});

it('keeps the team settings page reachable so a member can unlock themselves', function () {
    $this->team->update(['ip_allowlist' => ['203.0.113.0/24']]);

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
        ->get(route('teams.edit', $this->team->slug))
        ->assertOk();

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
        ->put(route('teams.ip-allowlist.update', $this->team->slug), ['ip_allowlist' => ''])
        ->assertSessionHasNoErrors();

    expect($this->team->fresh()->ip_allowlist)->toBeNull();
});

it('does not let a member without the update permission change the list', function () {
    $member = actingAsTeamMember(TeamRole::Member, $this->team);

    $this->actingAs($member)
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->put(route('teams.ip-allowlist.update', $this->team->slug), ['ip_allowlist' => '203.0.113.9'])
        ->assertForbidden();
});
