<?php

namespace App\Http\Controllers\Teams;

use App\Actions\Teams\SetTeamTwoFactorRequirement;
use App\Http\Controllers\Controller;
use App\Http\Requests\Teams\SaveTeamTwoFactorRequirementRequest;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class TeamTwoFactorRequirementController extends Controller
{
    /**
     * Set whether this team requires a second factor.
     */
    public function __invoke(
        SaveTeamTwoFactorRequirementRequest $request,
        Team $team,
        SetTeamTwoFactorRequirement $setTeamTwoFactorRequirement,
    ): RedirectResponse {
        Gate::authorize('update', $team);

        $required = $request->boolean('two_factor_required');

        $setTeamTwoFactorRequirement->handle($team, $required, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => $required
            ? __('Two-factor authentication is now required for this team.')
            : __('Two-factor authentication is no longer required for this team.'),
        ]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }
}
