<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProjectController extends Controller
{
    /**
     * List every project across the teams the user belongs to.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return ProjectResource::collection(
            Project::query()
                ->whereIn('team_id', $request->user()->teams()->select('teams.id'))
                ->with(['team', 'environments'])
                ->orderBy('name')
                ->get()
        );
    }
}
