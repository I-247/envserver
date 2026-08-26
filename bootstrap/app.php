<?php

use App\Http\Middleware\EnsureIpIsAllowed;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetTeamUrlDefaults;
use App\Http\Middleware\TrustProxies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Middleware\TrustProxies as BaseTrustProxies;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        // Voor de API is een lege waarde echte data: `AWS_BUCKET=` in een .env
        // is een sleutel met een lege waarde, niet een sleutel zonder waarde.
        // Zonder deze uitzondering maakt Laravel er null van en struikelt de
        // push over de string-regel.
        $middleware->convertEmptyStringsToNull(except: [
            fn (Request $request) => $request->is('api/*'),
        ]);

        // Without this the IP allow lists would see the load balancer rather
        // than the client. It stays opt in: trusting a proxy means trusting
        // whatever it puts in X-Forwarded-For.
        $middleware->replace(BaseTrustProxies::class, TrustProxies::class);

        // Ahead of the session on purpose: a request from an address that is
        // not on the operator's allow list should never start a session.
        $middleware->web(prepend: [
            EnsureIpIsAllowed::class,
        ]);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            SetTeamUrlDefaults::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
