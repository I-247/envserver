<?php

use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Teams\TeamController;
use App\Http\Controllers\Teams\TeamInvitationController;
use App\Http\Controllers\Teams\TeamIpAllowListController;
use App\Http\Controllers\Teams\TeamMemberController;
use App\Http\Controllers\Teams\TeamRotationPolicyController;
use App\Http\Controllers\Teams\TeamTwoFactorRequirementController;
use App\Http\Controllers\Teams\WebhookEndpointController;
use App\Http\Middleware\EnsureTeamIpIsAllowed;
use App\Http\Middleware\EnsureTeamMembership;
use App\Http\Middleware\EnsureTeamTwoFactorRequirementIsMet;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/appearance')->name('appearance.edit');

    Route::get('settings/teams', [TeamController::class, 'index'])->name('teams.index');
    Route::post('settings/teams', [TeamController::class, 'store'])->name('teams.store');

    /*
     * The team settings page and the two switches that can lock people out
     * stay reachable regardless of address or enrolment. Without that escape
     * hatch an admin who narrowed the allow list and then moved networks, or
     * who turned the second factor on and then lost their authenticator,
     * would have to be let back in from the database.
     */
    Route::middleware(EnsureTeamMembership::class)->group(function () {
        Route::get('settings/teams/{team}', [TeamController::class, 'edit'])->name('teams.edit');
        Route::post('settings/teams/{team}/switch', [TeamController::class, 'switch'])->name('teams.switch');
        Route::delete('settings/teams/{team}/leave', [TeamController::class, 'leave'])->name('teams.leave');

        Route::put('settings/teams/{team}/ip-allowlist', TeamIpAllowListController::class)->name('teams.ip-allowlist.update');
        Route::put('settings/teams/{team}/two-factor', TeamTwoFactorRequirementController::class)
            ->middleware('throttle:6,1')
            ->name('teams.two-factor.update');
    });

    Route::middleware([EnsureTeamMembership::class, EnsureTeamIpIsAllowed::class, EnsureTeamTwoFactorRequirementIsMet::class])->group(function () {
        Route::patch('settings/teams/{team}', [TeamController::class, 'update'])->name('teams.update');
        Route::put('settings/teams/{team}/rotation-policy', TeamRotationPolicyController::class)->name('teams.rotation-policy.update');

        Route::post('settings/teams/{team}/webhooks', [WebhookEndpointController::class, 'store'])->name('teams.webhooks.store');
        Route::delete('settings/teams/{team}/webhooks/{webhookEndpoint}', [WebhookEndpointController::class, 'destroy'])->name('teams.webhooks.destroy');
        Route::delete('settings/teams/{team}', [TeamController::class, 'destroy'])->name('teams.destroy');

        Route::patch('settings/teams/{team}/members/{user}', [TeamMemberController::class, 'update'])->name('teams.members.update');
        Route::delete('settings/teams/{team}/members/{user}', [TeamMemberController::class, 'destroy'])->name('teams.members.destroy');

        Route::post('settings/teams/{team}/invitations', [TeamInvitationController::class, 'store'])->name('teams.invitations.store');
        Route::delete('settings/teams/{team}/invitations/{invitation}', [TeamInvitationController::class, 'destroy'])->name('teams.invitations.destroy');
    });
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
