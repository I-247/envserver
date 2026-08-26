<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;

/**
 * Decides which proxies may speak for the client address.
 *
 * This matters more here than in most applications: every IP allow list in
 * Kluis compares against the address Laravel resolved, and behind a load
 * balancer that address is the balancer's unless it is trusted here.
 *
 * Trusting a proxy means believing its X-Forwarded-For header, and a header
 * is something a client can write. Naming nothing is therefore the default,
 * and only proxies you run belong on the list.
 */
class TrustProxies extends Middleware
{
    /**
     * Get the proxies that should be trusted.
     *
     * Read from config rather than set on the middleware configuration in
     * bootstrap: that closure runs before configuration is loaded.
     *
     * @return array<int, string>|string|null
     */
    protected function proxies()
    {
        $proxies = config('kluis.trusted_proxies');

        if ($proxies === [] || $proxies === null) {
            return parent::proxies();
        }

        return in_array('*', (array) $proxies, strict: true) ? '*' : $proxies;
    }
}
