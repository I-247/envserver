<?php

namespace App\Http\Middleware;

use App\Concerns\ResolvesRequestTeam;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps members without a second factor out of a team that demands one.
 *
 * Unlike the IP allow list this is not a dead end: the member can fix it
 * themselves, so they are sent to their security settings instead of being
 * shown a 403. That page sits outside every team-scoped group on purpose,
 * otherwise the redirect would loop straight back into this check.
 */
class EnsureTeamTwoFactorRequirementIsMet
{
    use ResolvesRequestTeam;

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $team = $this->team($request);
        $user = $request->user();

        if ($team === null || ! $team->two_factor_required || $user?->hasSecondFactor()) {
            return $next($request);
        }

        Log::warning('Blocked a member without a second factor from a team that requires one.', [
            'team' => $team->slug,
            'user_id' => $user?->id,
            'path' => $request->path(),
        ]);

        $message = __('The team ":name" requires two-factor authentication. Set up an authenticator app or a passkey to continue.', ['name' => $team->name]);

        // An API client has no page to be sent to; it gets the reason instead.
        if ($request->is('api/*') || $request->expectsJson()) {
            abort(403, $message);
        }

        Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

        return to_route('security.edit');
    }
}
