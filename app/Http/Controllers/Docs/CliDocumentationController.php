<?php

namespace App\Http\Controllers\Docs;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CliDocumentationController extends Controller
{
    /**
     * Show how to install and use envclient against this server.
     *
     * Public unless ENVSERVER_PUBLIC_CLI_DOCS turns it off, in which case a
     * guest is sent to the login page and lands back here afterwards. The
     * check lives here rather than as route middleware so the setting is
     * read per request, not frozen into a cached route list.
     */
    public function __invoke(Request $request): Response|RedirectResponse
    {
        if (! config('envserver.public_cli_docs') && $request->user() === null) {
            return redirect()->guest(route('login'));
        }

        return Inertia::render('docs/cli', [
            'server' => config('app.url'),
        ]);
    }
}
