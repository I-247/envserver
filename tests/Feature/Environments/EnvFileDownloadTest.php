<?php

use App\Actions\Variables\AttachVariableToEnvironment;
use App\Actions\Variables\CreateVariable;
use App\Enums\AuditAction;
use App\Enums\TeamRole;
use App\Models\AuditEvent;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Team;

beforeEach(function () {
    $this->team = Team::factory()->create(['slug' => 'acme']);
    $this->project = Project::factory()->for($this->team)->create(['slug' => 'webshop']);
    $this->environment = Environment::factory()->for($this->project)->create(['slug' => 'production']);
});

function downloadUrl(): string
{
    return '/acme/projects/webshop/environments/production/variables/export';
}

function vaulted(string $key, string $value): void
{
    $variable = app(CreateVariable::class)->handle(test()->team, $key, $value);

    app(AttachVariableToEnvironment::class)->handle($variable, test()->environment);
}

it('renders the environment as a .env file once the password checks out', function () {
    actingAsTeamMember(TeamRole::Admin, $this->team);
    vaulted('APP_ENV', 'production');
    vaulted('DB_PASSWORD', 'p$ssw0rd');

    $response = $this->post(downloadUrl(), ['password' => 'password']);

    $response->assertOk()
        ->assertDownload('kluis-webshop-production.env.txt');

    $body = $response->getContent();

    expect($body)->toContain('APP_ENV=production')
        // The $ is escaped exactly as phpdotenv wants it, so reading the file
        // back gives the password rather than an empty interpolation.
        ->and($body)->toContain('DB_PASSWORD="p\$ssw0rd"')
        ->and($body)->toContain('# Kluis export of');
});

it('refuses a wrong password and gives nothing away', function () {
    actingAsTeamMember(TeamRole::Admin, $this->team);
    vaulted('DB_PASSWORD', 'p$ssw0rd');

    $this->postJson(downloadUrl(), ['password' => 'not-my-password'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('password')
        ->assertDontSee('p$ssw0rd');

    expect(AuditEvent::where('action', AuditAction::EnvFileDownloaded)->count())->toBe(0);
});

it('requires a password at all', function () {
    actingAsTeamMember(TeamRole::Admin, $this->team);
    vaulted('APP_ENV', 'production');

    $this->postJson(downloadUrl(), [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('password');
});

it('keeps a member without the secret permission out', function () {
    actingAsTeamMember(TeamRole::Viewer, $this->team);
    vaulted('APP_ENV', 'production');

    $this->post(downloadUrl(), ['password' => 'password'])->assertForbidden();

    expect(AuditEvent::where('action', AuditAction::EnvFileDownloaded)->count())->toBe(0);
});

it('keeps someone outside the team out', function () {
    actingAsTeamMember(TeamRole::Owner);
    vaulted('APP_ENV', 'production');

    $this->post(downloadUrl(), ['password' => 'password'])->assertForbidden();
});

it('records who took the export', function () {
    $user = actingAsTeamMember(TeamRole::Admin, $this->team);
    vaulted('APP_ENV', 'production');
    vaulted('DB_PASSWORD', 'p$ssw0rd');

    $this->post(downloadUrl(), ['password' => 'password'])->assertOk();

    $event = AuditEvent::where('action', AuditAction::EnvFileDownloaded)->sole();

    expect($event->actor_id)->toBe($user->id)
        ->and($event->subject_id)->toBe($this->environment->id)
        ->and($event->metadata['variables'])->toBe(2)
        ->and($event->metadata['environment'])->toBe('production')
        // The trail says what was taken, never what was in it.
        ->and(json_encode($event->metadata))->not->toContain('p$ssw0rd');
});
