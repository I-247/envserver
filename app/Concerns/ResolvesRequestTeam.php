<?php

namespace App\Concerns;

use App\Models\Team;
use Illuminate\Http\Request;

trait ResolvesRequestTeam
{
    /**
     * Get the team associated with the request.
     */
    protected function team(Request $request): ?Team
    {
        $team = $request->route('current_team') ?? $request->route('team');

        if (is_string($team)) {
            $team = Team::where('slug', $team)->first();
        }

        return $team instanceof Team ? $team : null;
    }
}
