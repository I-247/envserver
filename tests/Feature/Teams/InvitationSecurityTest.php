<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();

    $this->team = Team::factory()->create();
    $this->owner = User::factory()->create();
    $this->team->members()->attach($this->owner, ['role' => TeamRole::Owner->value]);
});

it('does not let an admin invite someone as owner', function () {
    $admin = User::factory()->create();
    $this->team->members()->attach($admin, ['role' => TeamRole::Admin->value]);

    $this->actingAs($admin)
        ->post(route('teams.invitations.store', $this->team), [
            'email' => 'second-me@example.com',
            'role' => TeamRole::Owner->value,
        ])
        ->assertSessionHasErrors('role');

    expect(TeamInvitation::count())->toBe(0);
});

it('does not let an owner hand out the owner role by invitation either', function () {
    $this->actingAs($this->owner)
        ->post(route('teams.invitations.store', $this->team), [
            'email' => 'co-owner@example.com',
            'role' => TeamRole::Owner->value,
        ])
        ->assertSessionHasErrors('role');
});

it('refuses to accept an owner invitation that already exists', function () {
    $invitee = User::factory()->create(['email' => 'legacy@example.com']);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $this->team->id,
        'email' => $invitee->email,
        'role' => TeamRole::Owner,
        'invited_by' => $this->owner->id,
    ]);

    $this->actingAs($invitee)
        ->post(route('invitations.accept', $invitation))
        ->assertSessionHasErrors('invitation');

    expect($invitee->fresh()->belongsToTeam($this->team))->toBeFalse();
});

it('does not let an unverified account accept an invitation for its address', function () {
    $squatter = User::factory()->unverified()->create(['email' => 'new@example.com']);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $this->team->id,
        'email' => 'new@example.com',
        'role' => TeamRole::Admin,
        'invited_by' => $this->owner->id,
    ]);

    $this->actingAs($squatter)
        ->post(route('invitations.accept', $invitation))
        ->assertRedirect(route('verification.notice'));

    expect($squatter->fresh()->belongsToTeam($this->team))->toBeFalse()
        ->and($invitation->fresh()->accepted_at)->toBeNull();
});

it('sends every newly registered user a verification mail', function () {
    $this->post(route('register.store'), [
        'name' => 'New Person',
        'email' => 'fresh@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $user = User::where('email', 'fresh@example.com')->firstOrFail();

    expect($user->hasVerifiedEmail())->toBeFalse();

    Notification::assertSentTo($user, VerifyEmail::class);
});

it('keeps invitation codes off the team settings page', function () {
    $viewer = User::factory()->create();
    $this->team->members()->attach($viewer, ['role' => TeamRole::Viewer->value]);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $this->team->id,
        'email' => 'pending@example.com',
        'invited_by' => $this->owner->id,
    ]);

    $this->actingAs($viewer)
        ->get(route('teams.edit', $this->team))
        ->assertOk()
        ->assertDontSee($invitation->plainCode)
        ->assertInertia(fn ($page) => $page
            ->where('invitations.0.id', $invitation->id)
            ->missing('invitations.0.code'));
});

it('cancels an invitation by its id', function () {
    $invitation = TeamInvitation::factory()->create([
        'team_id' => $this->team->id,
        'email' => 'pending@example.com',
        'invited_by' => $this->owner->id,
    ]);

    $this->actingAs($this->owner)
        ->delete("/settings/teams/{$this->team->slug}/invitations/{$invitation->id}")
        ->assertRedirect(route('teams.edit', $this->team));

    expect(TeamInvitation::find($invitation->id))->toBeNull();
});

it('stores only a hash of the invitation code', function () {
    $invitation = TeamInvitation::factory()->create([
        'team_id' => $this->team->id,
        'email' => 'pending@example.com',
        'invited_by' => $this->owner->id,
    ]);

    expect($invitation->fresh()->code)->not->toBe($invitation->plainCode)
        ->and($invitation->fresh()->code)->toBe(TeamInvitation::hashCode($invitation->plainCode))
        ->and(TeamInvitation::withCode($invitation->plainCode)->sole()->id)->toBe($invitation->id)
        ->and(TeamInvitation::withCode($invitation->fresh()->code)->exists())->toBeFalse();
});

it('mails the plain code in an encrypted queue payload', function () {
    $this->actingAs($this->owner)
        ->post(route('teams.invitations.store', $this->team), [
            'email' => 'invitee@example.com',
            'role' => TeamRole::Member->value,
        ]);

    Notification::assertSentOnDemand(
        App\Notifications\Teams\TeamInvitation::class,
        function ($notification) {
            $stored = TeamInvitation::where('email', 'invitee@example.com')->sole()->code;

            return $notification instanceof ShouldBeEncrypted
                && TeamInvitation::hashCode($notification->code) === $stored
                && str_contains($notification->toMail((object) [])->actionUrl, $notification->code);
        },
    );
});
