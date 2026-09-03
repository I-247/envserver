<?php

namespace App\Http\Controllers\Teams;

use App\Actions\Teams\UpdateTeamIpAllowList;
use App\Http\Controllers\Controller;
use App\Http\Requests\Teams\SaveTeamIpAllowListRequest;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class TeamIpAllowListController extends Controller
{
    /**
     * Set the addresses the team may be reached from.
     */
    public function __invoke(
        SaveTeamIpAllowListRequest $request,
        Team $team,
        UpdateTeamIpAllowList $updateTeamIpAllowList,
    ): RedirectResponse {
        Gate::authorize('update', $team);

        $allowList = $request->allowList();

        $updateTeamIpAllowList->handle($team, $allowList, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => $allowList->isEmpty()
            ? __('IP restriction turned off.')
            : __('IP restriction updated.'),
        ]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }
}
