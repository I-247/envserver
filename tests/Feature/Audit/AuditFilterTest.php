<?php

use App\Enums\AuditAction;
use App\Enums\TeamRole;
use App\Models\AuditEvent;
use App\Models\Team;
use App\Models\User;

beforeEach(function () {
    $this->team = Team::factory()->create(['slug' => 'acme']);
    $this->admin = actingAsTeamMember(TeamRole::Admin, $this->team);
    $this->colleague = User::factory()->create(['name' => 'Robin']);
});

function auditEvent(array $attributes = []): AuditEvent
{
    return AuditEvent::create([
        'team_id' => test()->team->id,
        'action' => AuditAction::VariableCreated,
        'metadata' => ['key' => 'DB_PASSWORD'],
        ...$attributes,
    ]);
}

/**
 * @return array<int, mixed>
 */
function auditRows(string $query = ''): array
{
    $response = test()->get('/acme/audit'.$query)->assertOk();

    return $response->viewData('page')['props']['events'];
}

it('filters the trail by who did it', function () {
    auditEvent(['actor_id' => $this->admin->id, 'actor_name' => $this->admin->name]);
    auditEvent(['actor_id' => $this->colleague->id, 'actor_name' => 'Robin']);

    $rows = auditRows('?actor='.$this->colleague->id);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['actor'])->toBe('Robin');
});

it('filters on events without an actor behind them', function () {
    auditEvent(['actor_id' => $this->admin->id, 'actor_name' => $this->admin->name]);
    auditEvent(['actor_id' => null, 'actor_name' => null]);

    $rows = auditRows('?actor=system');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['actor'])->toBeNull();
});

it('filters the trail by what happened', function () {
    auditEvent(['action' => AuditAction::VariableCreated]);
    auditEvent(['action' => AuditAction::SecretRevealed]);

    $rows = auditRows('?action=secret.revealed');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['action'])->toBe('secret.revealed');
});

it('searches within the details, ignoring case', function () {
    auditEvent(['metadata' => ['key' => 'DB_PASSWORD']]);
    auditEvent(['metadata' => ['key' => 'MAIL_HOST']]);

    $rows = auditRows('?search=db_pass');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['metadata']['key'])->toBe('DB_PASSWORD');
});

it('combines the filters', function () {
    auditEvent([
        'actor_id' => $this->colleague->id,
        'actor_name' => 'Robin',
        'action' => AuditAction::SecretRevealed,
        'metadata' => ['key' => 'DB_PASSWORD'],
    ]);
    auditEvent([
        'actor_id' => $this->colleague->id,
        'actor_name' => 'Robin',
        'action' => AuditAction::VariableCreated,
        'metadata' => ['key' => 'DB_PASSWORD'],
    ]);
    auditEvent([
        'actor_id' => $this->admin->id,
        'actor_name' => $this->admin->name,
        'action' => AuditAction::SecretRevealed,
        'metadata' => ['key' => 'DB_PASSWORD'],
    ]);

    $rows = auditRows('?actor='.$this->colleague->id.'&action=secret.revealed&search=DB_PASSWORD');

    expect($rows)->toHaveCount(1);
});

it('rejects an action that is not a known one', function () {
    $this->get('/acme/audit?action=nonsense')->assertSessionHasErrors('action');
});

it('offers only the actors and actions this team actually has', function () {
    auditEvent([
        'actor_id' => $this->colleague->id,
        'actor_name' => 'Robin',
        'action' => AuditAction::SecretRevealed,
    ]);

    $otherTeam = Team::factory()->create();
    AuditEvent::create([
        'team_id' => $otherTeam->id,
        'actor_name' => 'Someone Else',
        'action' => AuditAction::ProjectDeleted,
    ]);

    $props = $this->get('/acme/audit')->assertOk()->viewData('page')['props'];

    expect(collect($props['actors'])->pluck('label')->all())->toBe(['Robin'])
        ->and(collect($props['actions'])->pluck('value')->all())->toBe(['secret.revealed']);
});

it('reflects the applied filters back to the page', function () {
    auditEvent();

    $props = $this->get('/acme/audit?search=DB')->assertOk()->viewData('page')['props'];

    expect($props['filters'])->toBe([
        'actor' => null,
        'action' => null,
        'search' => 'DB',
    ]);
});

it('splits a long trail into pages of fifty, newest first', function () {
    foreach (range(1, 60) as $number) {
        auditEvent(['metadata' => ['key' => "KEY_{$number}"]]);
    }

    $props = $this->get('/acme/audit')->assertOk()->viewData('page')['props'];

    expect($props['events'])->toHaveCount(50)
        ->and($props['events'][0]['metadata']['key'])->toBe('KEY_60')
        ->and($props['pagination'])->toBe([
            'currentPage' => 1,
            'lastPage' => 2,
            'perPage' => 50,
            'total' => 60,
            'from' => 1,
            'to' => 50,
        ]);
});

it('shows the older events on the next page', function () {
    foreach (range(1, 60) as $number) {
        auditEvent(['metadata' => ['key' => "KEY_{$number}"]]);
    }

    $props = $this->get('/acme/audit?page=2')->assertOk()->viewData('page')['props'];

    expect($props['events'])->toHaveCount(10)
        ->and($props['events'][0]['metadata']['key'])->toBe('KEY_10')
        ->and($props['pagination']['currentPage'])->toBe(2)
        ->and($props['pagination']['from'])->toBe(51);
});

it('keeps the filters applied while paging', function () {
    foreach (range(1, 60) as $number) {
        auditEvent([
            'actor_id' => $this->colleague->id,
            'actor_name' => 'Robin',
            'metadata' => ['key' => "KEY_{$number}"],
        ]);
    }

    auditEvent(['actor_id' => $this->admin->id, 'actor_name' => $this->admin->name]);

    $props = $this->get('/acme/audit?actor='.$this->colleague->id.'&page=2')
        ->assertOk()
        ->viewData('page')['props'];

    expect($props['pagination']['total'])->toBe(60)
        ->and(collect($props['events'])->pluck('actor')->unique()->all())->toBe(['Robin']);
});

it('counts only the filtered events in the total', function () {
    auditEvent(['metadata' => ['key' => 'DB_PASSWORD']]);
    auditEvent(['metadata' => ['key' => 'MAIL_HOST']]);

    $props = $this->get('/acme/audit?search=DB_PASSWORD')->assertOk()->viewData('page')['props'];

    expect($props['pagination']['total'])->toBe(1);
});

it('rejects a page number that is not a page', function () {
    $this->get('/acme/audit?page=0')->assertSessionHasErrors('page');
});
