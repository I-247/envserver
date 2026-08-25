<?php

namespace App\Http\Controllers\Environments;

use App\Actions\Variables\AttachVariableToEnvironment;
use App\Actions\Variables\CreateVariable;
use App\Actions\Variables\UpdateVariableValue;
use App\Http\Controllers\Controller;
use App\Http\Requests\Variables\StoreVariableRequest;
use App\Http\Requests\Variables\UpdateVariableRequest;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Team;
use App\Models\Variable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class VariableController extends Controller
{
    /**
     * Create a variable and attach it to this environment.
     */
    public function store(
        StoreVariableRequest $request,
        Team $currentTeam,
        Project $project,
        Environment $environment,
        CreateVariable $create,
        AttachVariableToEnvironment $attach,
    ): RedirectResponse {
        Gate::authorize('manageVariables', $project);

        $variable = $create->handle(
            $currentTeam,
            $request->validated('key'),
            $request->validated('value'),
            $request->user(),
            $request->validated('description'),
        );

        $attach->handle($variable, $environment);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Variable added.')]);

        return back();
    }

    /**
     * Change a variable's value, description or alias.
     */
    public function update(
        UpdateVariableRequest $request,
        Team $currentTeam,
        Project $project,
        Environment $environment,
        Variable $variable,
        UpdateVariableValue $update,
        AttachVariableToEnvironment $attach,
    ): RedirectResponse {
        Gate::authorize('manageVariables', $project);

        if ($request->has('value')) {
            $update->handle(
                $variable,
                $request->validated('value'),
                $request->user(),
                $request->validated('note'),
            );
        }

        if ($request->has('description')) {
            $variable->update(['description' => $request->validated('description')]);
        }

        if ($request->has('alias_key')) {
            $attach->handle($variable, $environment, $request->validated('alias_key'));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Variable updated.')]);

        return back();
    }

    /**
     * Remove a variable from this environment.
     *
     * The variable itself survives: it may still be in use elsewhere, and its
     * history is worth keeping either way.
     */
    public function destroy(
        Team $currentTeam,
        Project $project,
        Environment $environment,
        Variable $variable,
    ): RedirectResponse {
        Gate::authorize('manageVariables', $project);

        $environment->assignments()->where('variable_id', $variable->id)->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Variable removed from this environment.')]);

        return back();
    }

    /**
     * Reveal a variable's plaintext value.
     */
    public function reveal(
        Request $request,
        Team $currentTeam,
        Project $project,
        Environment $environment,
        Variable $variable,
    ): JsonResponse {
        Gate::authorize('viewSecrets', $project);

        abort_if($variable->currentVersion() === null, 404);

        return response()->json([
            'value' => $variable->currentVersion()->reveal(),
        ])->header('Cache-Control', 'no-store');
    }
}
