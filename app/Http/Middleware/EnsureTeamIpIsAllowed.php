<?php

namespace App\Http\Middleware;

use App\Concerns\ResolvesRequestTeam;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps a team's own pages on the allow list that team configured.
 *
 * This narrows the operator's list in config, it never widens it: a team can
 * only ever cut its own access down further. Machine access is not covered
 * here, since a deploy token has an allow list of its own on the environment.
 */
class EnsureTeamIpIsAllowed
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

        if ($team === null || $team->ipAllowList()->allows($request->ip())) {
            return $next($request);
        }

        Log::warning('Blocked a request from an address outside a team allow list.', [
            'ip' => $request->ip(),
            'team' => $team->slug,
            'path' => $request->path(),
        ]);

        abort(403, __('The team ":name" cannot be reached from your network.', ['name' => $team->name]));
    }
}
