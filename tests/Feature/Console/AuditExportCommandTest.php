<?php

use App\Enums\AuditAction;
use App\Models\AuditEvent;
use App\Models\Team;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->acme = Team::factory()->create(['slug' => 'acme']);
    $this->globex = Team::factory()->create(['slug' => 'globex']);
    $this->exportPath = sys_get_temp_dir().'/audit-export-'.uniqid().'.csv';
});

afterEach(function () {
    if (file_exists($this->exportPath)) {
        unlink($this->exportPath);
    }
});

function exportedAuditEvent(Team $team, string $at, array $attributes = []): AuditEvent
{
    $event = new AuditEvent([
        'team_id' => $team->id,
        'action' => AuditAction::VariableCreated,
        'actor_name' => 'Robin',
        'metadata' => ['key' => 'DB_PASSWORD', 'project' => 'webshop'],
        'ip_address' => '203.0.113.9',
        ...$attributes,
    ]);
    $event->created_at = Carbon::parse($at);
    $event->save();

    return $event;
}

/**
 * @return list<array<string, string>>
 */
function readAuditExport(string $path): array
{
    $handle = fopen($path, 'r');
    $header = fgetcsv($handle, escape: '');
    $rows = [];

    while (($row = fgetcsv($handle, escape: '')) !== false) {
        $rows[] = array_combine($header, $row);
    }

    fclose($handle);

    return $rows;
}

it('exports every team\'s audit trail, oldest first', function () {
    $first = exportedAuditEvent($this->acme, '2026-09-01 10:00');
    exportedAuditEvent($this->globex, '2026-09-02 10:00', ['action' => AuditAction::ReleasePublished]);

    $this->artisan('envserver:audit-export', ['--output' => $this->exportPath])
        ->expectsOutputToContain('2 audit event(s) written')
        ->assertSuccessful();

    $rows = readAuditExport($this->exportPath);

    expect($rows)->toHaveCount(2)
        ->and($rows[0])->toMatchArray([
            'id' => (string) $first->id,
            'team' => 'acme',
            'action' => 'variable.created',
            'label' => 'Variable created',
            'actor' => 'Robin',
            'ip_address' => '203.0.113.9',
        ])
        ->and(json_decode($rows[0]['metadata'], true))->toBe(['key' => 'DB_PASSWORD', 'project' => 'webshop'])
        ->and($rows[1]['team'])->toBe('globex')
        ->and($rows[1]['action'])->toBe('release.published');
});

it('writes only the header when there is nothing to export', function () {
    $this->artisan('envserver:audit-export', ['--output' => $this->exportPath])
        ->expectsOutputToContain('0 audit event(s) written')
        ->assertSuccessful();

    expect(trim(file_get_contents($this->exportPath)))
        ->toBe('id,created_at,team,action,label,actor,actor_id,subject_type,subject_id,ip_address,metadata');
});

it('limits the export to one team', function () {
    exportedAuditEvent($this->acme, '2026-09-01 10:00');
    exportedAuditEvent($this->globex, '2026-09-01 11:00');

    $this->artisan('envserver:audit-export', ['--output' => $this->exportPath, '--team' => 'globex'])
        ->assertSuccessful();

    expect(collect(readAuditExport($this->exportPath))->pluck('team')->all())->toBe(['globex']);
});

it('limits the export to a date range, counting a bare until date as the whole day', function () {
    exportedAuditEvent($this->acme, '2026-08-31 23:59');
    exportedAuditEvent($this->acme, '2026-09-01 00:00', ['actor_name' => 'First']);
    exportedAuditEvent($this->acme, '2026-09-02 18:00', ['actor_name' => 'Last']);
    exportedAuditEvent($this->acme, '2026-09-03 00:00');

    $this->artisan('envserver:audit-export', [
        '--output' => $this->exportPath,
        '--from' => '2026-09-01',
        '--until' => '2026-09-02',
    ])->assertSuccessful();

    expect(collect(readAuditExport($this->exportPath))->pluck('actor')->all())->toBe(['First', 'Last']);
});

it('keeps spreadsheet formulas from running', function () {
    exportedAuditEvent($this->acme, '2026-09-01 10:00', ['actor_name' => '=HYPERLINK("https://evil.example","x")']);

    $this->artisan('envserver:audit-export', ['--output' => $this->exportPath])->assertSuccessful();

    expect(readAuditExport($this->exportPath)[0]['actor'])->toBe('\'=HYPERLINK("https://evil.example","x")');
});

it('creates the file readable by its owner only', function () {
    $this->artisan('envserver:audit-export', ['--output' => $this->exportPath])->assertSuccessful();

    expect(fileperms($this->exportPath) & 0777)->toBe(0600);
});

it('refuses to overwrite an existing file', function () {
    file_put_contents($this->exportPath, 'keep me');

    $this->artisan('envserver:audit-export', ['--output' => $this->exportPath])
        ->expectsOutputToContain('already exists')
        ->assertFailed();

    expect(file_get_contents($this->exportPath))->toBe('keep me');
});

it('refuses a team that does not exist', function () {
    $this->artisan('envserver:audit-export', ['--output' => $this->exportPath, '--team' => 'nope'])
        ->expectsOutputToContain('No team with the slug "nope"')
        ->assertFailed();

    expect(file_exists($this->exportPath))->toBeFalse();
});

it('refuses a date it cannot read, or a range that is backwards', function () {
    $this->artisan('envserver:audit-export', ['--output' => $this->exportPath, '--from' => 'yesterdayish'])
        ->assertFailed();

    $this->artisan('envserver:audit-export', [
        '--output' => $this->exportPath,
        '--from' => '2026-09-02',
        '--until' => '2026-09-01',
    ])->expectsOutputToContain('--from is after --until')->assertFailed();

    expect(file_exists($this->exportPath))->toBeFalse();
});
