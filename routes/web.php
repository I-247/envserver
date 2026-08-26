<?php

use App\Http\Controllers\Audit\AuditController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Environments\DeployTokenController;
use App\Http\Controllers\Environments\EnvFileDownloadController;
use App\Http\Controllers\Environments\EnvFileImportController;
use App\Http\Controllers\Environments\EnvironmentController;
use App\Http\Controllers\Environments\ReleaseController;
use App\Http\Controllers\Environments\SharedVariableController;
use App\Http\Controllers\Environments\VariableController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\Projects\DriftController;
use App\Http\Controllers\Projects\ProjectController;
use App\Http\Controllers\Teams\TeamInvitationController;
use App\Http\Middleware\EnsureTeamIpIsAllowed;
use App\Http\Middleware\EnsureTeamMembership;
use App\Http\Middleware\EnsureTeamTwoFactorRequirementIsMet;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

Route::prefix('{current_team}')
    ->middleware(['auth', 'verified', EnsureTeamMembership::class, EnsureTeamIpIsAllowed::class, EnsureTeamTwoFactorRequirementIsMet::class])
    ->scopeBindings()
    ->group(function () {
        Route::get('dashboard', DashboardController::class)->name('dashboard');

        Route::get('audit', AuditController::class)->name('audit');

        Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');
        Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');
        Route::get('projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
        Route::patch('projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
        Route::delete('projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');

        Route::get('projects/{project}/drift', DriftController::class)->name('projects.drift');

        Route::post('projects/{project}/environments', [EnvironmentController::class, 'store'])->name('environments.store');

        Route::prefix('projects/{project}/environments/{environment}')
            ->name('environments.')
            ->group(function () {
                Route::get('/', [EnvironmentController::class, 'show'])->name('show');
                Route::patch('/', [EnvironmentController::class, 'update'])->name('update');
                Route::delete('/', [EnvironmentController::class, 'destroy'])->name('destroy');

                Route::post('variables', [VariableController::class, 'store'])->name('variables.store');
                Route::patch('variables/{variable}', [VariableController::class, 'update'])->name('variables.update');
                Route::delete('variables/{variable}', [VariableController::class, 'destroy'])->name('variables.destroy');
                // Revealing one secret is cheaper than the .env export next
                // to it, so it carries a wider limit than throttle:6,1 there.
                // It still has to have one: without a limit the reveal button
                // is a way to walk out with every value in an environment one
                // request at a time, past the password the export asks for.
                Route::get('variables/{variable}/reveal', [VariableController::class, 'reveal'])
                    ->middleware('throttle:20,1')
                    ->name('variables.reveal');

                Route::post('variables/shared', [SharedVariableController::class, 'store'])->name('variables.share');
                Route::patch('variables/{variable}/shareable', [SharedVariableController::class, 'update'])->name('variables.shareable');

                Route::post('variables/import/preview', [EnvFileImportController::class, 'preview'])->name('envFile.preview');
                Route::post('variables/import', [EnvFileImportController::class, 'store'])->name('envFile.store');

                Route::post('variables/export', EnvFileDownloadController::class)
                    ->middleware('throttle:6,1')
                    ->name('envFile.download');

                Route::get('releases', [ReleaseController::class, 'index'])->name('releases.index');
                Route::post('releases', [ReleaseController::class, 'store'])->name('releases.store');
                Route::post('releases/{release}/rollback', [ReleaseController::class, 'rollback'])->name('releases.rollback');

                Route::get('deploy-tokens', [DeployTokenController::class, 'index'])->name('deploy-tokens.index');
                Route::post('deploy-tokens', [DeployTokenController::class, 'store'])->name('deploy-tokens.store');
                Route::delete('deploy-tokens/{deployToken}', [DeployTokenController::class, 'destroy'])->name('deploy-tokens.destroy');
            });
    });

Route::middleware(['auth'])->group(function () {
    Route::post('invitations/{invitation}/accept', [TeamInvitationController::class, 'accept'])->name('invitations.accept');
    Route::delete('invitations/{invitation}', [TeamInvitationController::class, 'decline'])->name('invitations.decline');
});

require __DIR__.'/settings.php';
