<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ApiScope;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Tells the CLI everything it needs to start a login.
 *
 * Unauthenticated on purpose: none of it is secret, and it means "envclient login"
 * only has to be pointed at a server URL rather than configured by hand.
 */
class CliDiscoveryController extends Controller
{
    /**
     * Describe how to authenticate against this Envserver instance.
     */
    public function __invoke(): JsonResponse
    {
        $clientId = config('envserver.cli_client_id');

        abort_if(blank($clientId), 503, 'This Envserver instance has no CLI client yet. Run "php artisan envserver:cli-client".');

        return response()->json([
            'data' => [
                'client_id' => (string) $clientId,
                'device_code_endpoint' => url('/oauth/device/code'),
                'token_endpoint' => url('/oauth/token'),
                'api_base' => url('/api/v1'),
                'scopes' => array_keys(ApiScope::map()),
            ],
        ]);
    }
}
