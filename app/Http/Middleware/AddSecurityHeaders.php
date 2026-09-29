<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response as IlluminateResponse;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds the response headers a page holding secrets should always carry.
 *
 * No framing (the reveal and export buttons must not be clickable through
 * somebody else's page), no plugins, and forms and <base> only to this
 * origin, on every response.
 *
 * Pages rendered from the Inertia root view also get a nonce-based
 * script-src: Vite puts the nonce on its tags, app.blade.php on its one
 * inline script, and 'strict-dynamic' lets those load their own chunks. An
 * injected <script> has no nonce and does not run. Other HTML (Horizon, the
 * debug error page) keeps its inline scripts working by not getting one.
 */
class AddSecurityHeaders
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        Vite::useCspNonce();

        $response = $next($request);

        $policy = "frame-ancestors 'none'; object-src 'none'; base-uri 'self'; form-action 'self'";

        if ($this->rendersTheApp($response)) {
            // 'self' is only a fallback for browsers without CSP 3, which
            // ignore 'strict-dynamic'; the others ignore 'self' next to it.
            $policy .= "; script-src 'nonce-".Vite::cspNonce()."' 'strict-dynamic' 'self'";
        }

        $response->headers->set('Content-Security-Policy', $policy, false);
        $response->headers->set('X-Frame-Options', 'DENY', false);
        $response->headers->set('X-Content-Type-Options', 'nosniff', false);
        // Invitation codes and signed links travel in URLs; they must not
        // follow the user to another site in a Referer header.
        $response->headers->set('Referrer-Policy', 'same-origin', false);
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()', false);

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains', false);
        }

        return $response;
    }

    /**
     * Determine whether the response is a full page from the Inertia root view.
     */
    private function rendersTheApp(Response $response): bool
    {
        return $response instanceof IlluminateResponse
            && $response->original instanceof View
            && $response->original->name() === 'app';
    }
}
