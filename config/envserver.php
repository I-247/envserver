<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Master Key
    |--------------------------------------------------------------------------
    |
    | The master key wraps every team's data encryption key. It is deliberately
    | kept separate from APP_KEY: rotating APP_KEY (which invalidates sessions
    | and cookies) must never risk making stored secrets unreadable.
    |
    | Generate one with: php artisan envserver:master-key
    |
    */

    'master_key' => env('ENVSERVER_MASTER_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Previous Master Keys
    |--------------------------------------------------------------------------
    |
    | A comma separated list of retired master keys. Wrapped data keys are
    | always written with the current master key, but unwrapping falls back
    | to these so a rotation can be rolled out without downtime.
    |
    */

    'previous_master_keys' => array_values(array_filter(
        explode(',', (string) env('ENVSERVER_PREVIOUS_MASTER_KEYS', ''))
    )),

    /*
    |--------------------------------------------------------------------------
    | Cipher
    |--------------------------------------------------------------------------
    |
    | The OpenSSL cipher used for both wrapping data keys and encrypting
    | variable values. AEAD is required so tampering is detected on decrypt.
    |
    */

    'cipher' => 'aes-256-gcm',

    /*
    |--------------------------------------------------------------------------
    | CLI Client
    |--------------------------------------------------------------------------
    |
    | The OAuth device flow client the Envserver CLI authenticates with. Published
    | over an unauthenticated discovery endpoint so "envclient login" only needs
    | the server URL: a device flow client id is public by design.
    |
    | Create one with: php artisan envserver:cli-client
    |
    */

    'cli_client_id' => env('ENVSERVER_CLI_CLIENT_ID'),

    /*
    |--------------------------------------------------------------------------
    | Sign In IP Allow List
    |--------------------------------------------------------------------------
    |
    | A comma separated list of IP addresses and CIDR ranges that may reach
    | the web application at all, signing in included. Leave it empty and no
    | restriction applies.
    |
    | This is the operator's net, set on the server and not editable from the
    | interface, so a compromised account cannot widen it. Teams can narrow
    | it further for themselves from their team settings.
    |
    | Example: ENVSERVER_IP_ALLOWLIST="203.0.113.4,10.0.0.0/8,2001:db8::/32"
    |
    */

    'ip_allowlist' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('ENVSERVER_IP_ALLOWLIST', ''))
    ))),

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | Every allow list above compares against the client address Laravel sees.
    | Behind a load balancer or a reverse proxy that address is the proxy's
    | unless the proxy is trusted here, at which point X-Forwarded-For is
    | believed instead.
    |
    | Leave this empty when the application is reached directly. Only ever
    | name proxies you control: a trusted proxy's X-Forwarded-For header is
    | taken at face value, and a header is something a client can write.
    |
    | Use "*" for a platform whose load balancer address is not fixed, such as
    | Laravel Cloud, and only when nothing else can reach the origin.
    |
    */

    'trusted_proxies' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('ENVSERVER_TRUSTED_PROXIES', ''))
    ))),

    /*
    |--------------------------------------------------------------------------
    | Registration
    |--------------------------------------------------------------------------
    |
    | Whether anyone may create an account from the public registration page.
    | Turning this off does not lock out invited users: a pending team
    | invitation always lets its recipient register, since that is the only
    | way for a brand new person to accept it.
    |
    */

    'registration_enabled' => (bool) env('ENVSERVER_REGISTRATION_ENABLED', true),

];
