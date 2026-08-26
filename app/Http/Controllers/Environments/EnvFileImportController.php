<?php

namespace App\Http\Controllers\Environments;

use App\Actions\Variables\PushVariables;
use App\Actions\Variables\ResolveEnvironmentVariables;
use App\Data\ResolvedVariable;
use App\Http\Controllers\Controller;
use App\Http\Requests\Variables\ImportEnvFileRequest;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Imports a pasted .env file into an environment.
 *
 * It takes two requests on purpose: the first one only reports which keys
 * would be new and which ones the environment already has, so that the choice
 * between overwriting the vault and keeping it is made with the conflicts in
 * view rather than blind.
 */
class EnvFileImportController extends Controller
{
    /**
     * Report what importing this paste would touch.
     *
     * Only key names travel back, never a value: the answer to "is this key
     * already in the vault" says nothing about what is in it.
     */
    public function preview(
        ImportEnvFileRequest $request,
        Team $currentTeam,
        Project $project,
        Environment $environment,
        ResolveEnvironmentVariables $resolve,
    ): JsonResponse {
        Gate::authorize('manageVariables', $project);

        $incoming = array_keys($request->variables());
        $existing = $resolve->handle($environment)
            ->map(fn (ResolvedVariable $entry) => $entry->key)
            ->all();

        return response()->json([
            'adding' => array_values(array_diff($incoming, $existing)),
            'conflicting' => array_values(array_intersect($incoming, $existing)),
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * Apply the paste to the environment.
     */
    public function store(
        ImportEnvFileRequest $request,
        Team $currentTeam,
        Project $project,
        Environment $environment,
        PushVariables $push,
    ): RedirectResponse {
        Gate::authorize('manageVariables', $project);

        $result = $push->handle(
            $environment,
            $request->variables(),
            $request->user(),
            $request->strategy(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => $this->summary($result)]);

        return back();
    }

    /**
     * Say what the import did, counting only what actually happened.
     *
     * @param  array{created: int, updated: int, unchanged: int, skipped: int, shared_impact: list<string>}  $result
     */
    private function summary(array $result): string
    {
        $parts = [];

        foreach (['created' => 'added', 'updated' => 'updated', 'skipped' => 'left alone', 'unchanged' => 'unchanged'] as $key => $label) {
            if ($result[$key] > 0) {
                $parts[] = "{$result[$key]} {$label}";
            }
        }

        $summary = 'Imported: '.implode(', ', $parts).'.';

        if ($result['shared_impact'] !== []) {
            $summary .= ' Also changed in '.implode(', ', $result['shared_impact']).'.';
        }

        return $summary;
    }
}
