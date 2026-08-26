<?php

namespace App\Http\Controllers\Projects;

use App\Actions\Environments\CompareEnvironments;
use App\Data\DriftEntry;
use App\Http\Controllers\Controller;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Team;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class DriftController extends Controller
{
    /**
     * Show every key of the project side by side across its environments.
     */
    public function __invoke(
        Team $currentTeam,
        Project $project,
        CompareEnvironments $compare,
    ): Response {
        Gate::authorize('view', $project);

        $entries = $compare->handle($project);

        return Inertia::render('projects/drift', [
            'project' => ['name' => $project->name, 'slug' => $project->slug],
            'environments' => $compare->environments($project)->map(fn (Environment $environment) => [
                'name' => $environment->name,
                'slug' => $environment->slug,
                // The environments a duplicate value is worth warning about.
                'guarded' => ! $environment->auto_publish,
            ]),
            // Presence and a value group per key, never a value: this page
            // exists to be read next to somebody, and "these two are the
            // same" is the whole answer it owes.
            'entries' => $entries->map(fn (DriftEntry $entry) => [
                'key' => $entry->key,
                'groups' => $entry->groups,
                'missingIn' => $entry->missingIn(),
                'reusedIn' => $entry->reusedIn,
                'differs' => $entry->differs(),
            ]),
            'summary' => [
                'keys' => $entries->count(),
                'missing' => $entries->filter(fn (DriftEntry $entry) => ! $entry->isEverywhere())->count(),
                'reused' => $entries->filter(fn (DriftEntry $entry) => $entry->reusedIn !== [])->count(),
            ],
        ]);
    }
}
