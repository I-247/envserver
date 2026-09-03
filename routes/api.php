<?php

use App\Enums\ApiScope;
use App\Http\Controllers\Api\V1\CliDiscoveryController;
use App\Http\Controllers\Api\V1\DeployController;
use App\Http\Controllers\Api\V1\EnvironmentController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Middleware\EnsureTeamMembership;
use App\Http\Middleware\ResolveDeployToken;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Http\Middleware\CheckToken;

Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::get('cli', CliDiscoveryController::class)->name('cli');

    /*
     * Machine endpoints. The presented deploy token already identifies one
     * environment, so these routes deliberately take no project or
     * environment in the path: there is nothing to point somewhere else.
     */
    Route::prefix('deploy')->name('deploy.')
        ->middleware(ResolveDeployToken::using(ApiScope::EnvironmentRead->value))
        ->group(function () {
            Route::get('release', [DeployController::class, 'release'])->name('release');
            Route::get('env', [DeployController::class, 'env'])->name('env');
            Route::get('releases', [DeployController::class, 'releases'])->name('releases');
        });

    /*
     * A separate group, not appended to the one above: a token that may only
     * push should not also be required to hold env:read, and vice versa.
     * Every deploy token gets env:read; env:write is opt-in per token.
     */
    Route::prefix('deploy')->name('deploy.')
        ->middleware(ResolveDeployToken::using(ApiScope::EnvironmentWrite->value))
        ->group(function () {
            Route::post('variables', [DeployController::class, 'push'])->name('variables.push');
        });

    /*
     * Developer endpoints, reached with a personal token from the device
     * flow. These do name the environment, because a personal token spans
     * every team the developer belongs to.
     */
    Route::middleware('auth:api')->group(function () {
        Route::get('projects', [ProjectController::class, 'index'])
            ->middleware(CheckToken::using(ApiScope::ProjectsRead->value))
            ->name('projects.index');

        Route::prefix('teams/{team}/projects/{project}/environments/{environment}')
            ->middleware(EnsureTeamMembership::class)
            ->scopeBindings()
            ->name('environments.')
            ->group(function () {
                Route::middleware(CheckToken::using(ApiScope::EnvironmentRead->value))->group(function () {
                    Route::get('release', [EnvironmentController::class, 'release'])->name('release');
                    Route::get('env', [EnvironmentController::class, 'env'])->name('env');
                    Route::get('releases', [EnvironmentController::class, 'releases'])->name('releases');
                    Route::get('pending', [EnvironmentController::class, 'pending'])->name('pending');
                });

                Route::post('variables', [EnvironmentController::class, 'push'])
                    ->middleware(CheckToken::using(ApiScope::EnvironmentWrite->value))
                    ->name('variables.push');

                Route::post('releases', [EnvironmentController::class, 'publish'])
                    ->middleware(CheckToken::using(ApiScope::EnvironmentPublish->value))
                    ->name('releases.publish');
            });
    });
});
