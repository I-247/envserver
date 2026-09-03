<?php

namespace App\Http\Controllers;

use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    /**
     * Send visitors straight into the application: members land on their
     * current team's dashboard, everyone else on the login screen.
     */
    public function __invoke(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user) {
            return to_route('login');
        }

        $team = $user->currentTeam ?? $user->personalTeam() ?? $user->teams()->first();

        if (! $team instanceof Team) {
            return to_route('profile.edit');
        }

        return to_route('dashboard', ['current_team' => $team->slug]);
    }
}
