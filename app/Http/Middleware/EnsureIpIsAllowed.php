<?php

namespace App\Http\Middleware;

use App\Support\IpAllowList;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the web application, signing in included, on the operator's allow list.
 *
 * The list lives in configuration rather than in the database on purpose: it
 * is the net under everything else, so somebody who has taken over an account
 * must not be able to widen it from the interface.
 *
 * It runs ahead of the session so a request from an address that is not on
 * the list never gets to touch a session at all.
 */
class EnsureIpIsAllowed
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $allowList = IpAllowList::make(config('kluis.ip_allowlist'));

        if ($allowList->allows($request->ip())) {
            return $next($request);
        }

        Log::warning('Blocked a request from an address outside the sign in allow list.', [
            'ip' => $request->ip(),
            'path' => $request->path(),
        ]);

        abort(403, __('This application cannot be reached from your network.'));
    }
}
