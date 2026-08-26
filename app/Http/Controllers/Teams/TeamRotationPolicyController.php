<?php

namespace App\Http\Controllers\Teams;

use App\Actions\Teams\SetTeamRotationPolicy;
use App\Http\Controllers\Controller;
use App\Http\Requests\Teams\SaveTeamRotationPolicyRequest;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class TeamRotationPolicyController extends Controller
{
    /**
     * Set how long this team's secrets may go unchanged.
     */
    public function __invoke(
        SaveTeamRotationPolicyRequest $request,
        Team $team,
        SetTeamRotationPolicy $setTeamRotationPolicy,
    ): RedirectResponse {
        Gate::authorize('update', $team);

        $days = $request->days();

        $setTeamRotationPolicy->handle($team, $days, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => $days === null
            ? __('Rotation policy turned off.')
            : trans_choice('Secrets are now flagged after :count day.|Secrets are now flagged after :count days.', $days, ['count' => $days]),
        ]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }
}
