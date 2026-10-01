<?php

namespace App\Http\Controllers;

use App\Actions\Search\SearchTeam;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    /**
     * Answer the command palette: everything in the team matching the query.
     *
     * JSON rather than an Inertia visit, because the palette asks on every
     * keystroke and must not touch the page underneath it.
     */
    public function __invoke(Request $request, Team $currentTeam, SearchTeam $search): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ]);

        return response()->json($search->handle($currentTeam, $request->user(), $validated['q']));
    }
}
