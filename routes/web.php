<?php

use App\Http\Controllers\Audit\AuditController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Environments\DeployTokenController;
use App\Http\Controllers\Environments\EnvironmentController;
use App\Http\Controllers\Environments\ReleaseController;
use App\Http\Controllers\Environments\VariableController;
use App\Http\Controllers\Projects\ProjectController;
use App\Http\Controllers\Teams\TeamInvitationController;
use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::prefix('{current_team}')
    ->middleware(['auth', 'verified', EnsureTeamMembership::class])
    ->scopeBindings()
    ->group(function () {
        Route::get('dashboard', DashboardController::class)->name('dashboard');

        Route::get('audit', AuditController::class)->name('audit');

        Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');
        Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');
        Route::get('projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
        Route::patch('projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
        Route::delete('projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');

        Route::prefix('projects/{project}/environments/{environment}')
            ->name('environments.')
            ->group(function () {
                Route::get('/', [EnvironmentController::class, 'show'])->name('show');

                Route::post('variables', [VariableController::class, 'store'])->name('variables.store');
                Route::patch('variables/{variable}', [VariableController::class, 'update'])->name('variables.update');
                Route::delete('variables/{variable}', [VariableController::class, 'destroy'])->name('variables.destroy');
                Route::get('variables/{variable}/reveal', [VariableController::class, 'reveal'])->name('variables.reveal');

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
