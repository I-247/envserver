<?php

namespace App\Http\Controllers\Environments;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Variables\AttachVariableToEnvironment;
use App\Actions\Variables\CreateVariable;
use App\Actions\Variables\DetachVariableFromEnvironment;
use App\Actions\Variables\UpdateVariableValue;
use App\Enums\AuditAction;
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
            $project,
            $request->validated('rotate_after_days'),
        );

        $attach->handle($variable, $environment);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Variable added.')]);

        return back();
    }

    /**
     * Change a variable's value, description or alias.
     *
     * The alias belongs to this environment, but the value and description
     * belong to the variable itself, and through it to every environment
     * using it. A borrowing project may therefore only rename it here: a
     * value it did not create is changed where it lives, by the project that
     * answers for it.
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

        abort_if(
            $variable->isBorrowedBy($project) && ($request->has('value') || $request->has('description') || $request->has('rotate_after_days')),
            403,
            'This variable is owned by another project.',
        );

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

        if ($request->has('rotate_after_days')) {
            $variable->update(['rotate_after_days' => $request->validated('rotate_after_days')]);
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
     * history is worth keeping either way. If this environment was the owning
     * project's last hold on a shared variable, another project inherits it.
     */
    public function destroy(
        Request $request,
        Team $currentTeam,
        Project $project,
        Environment $environment,
        Variable $variable,
        DetachVariableFromEnvironment $detach,
    ): RedirectResponse {
        Gate::authorize('manageVariables', $project);

        $heir = $detach->handle($variable, $environment, $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $heir === null
                ? __('Variable removed from this environment.')
                : __(':key is still shared, so :project now owns it.', [
                    'key' => $variable->key,
                    'project' => $heir->name,
                ]),
        ]);

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
        RecordAuditEvent $audit,
    ): JsonResponse {
        Gate::authorize('viewSecrets', $project);

        abort_if($variable->currentVersion() === null, 404);

        $audit->handle($currentTeam, AuditAction::SecretRevealed, $request->user(), $variable, [
            'key' => $variable->key,
            'project' => $project->slug,
            'environment' => $environment->slug,
        ]);

        return response()->json([
            'value' => $variable->currentVersion()->reveal(),
        ])->header('Cache-Control', 'no-store');
    }
}
