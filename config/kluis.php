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
    | Generate one with: php artisan kluis:master-key
    |
    */

    'master_key' => env('KLUIS_MASTER_KEY'),

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
        explode(',', (string) env('KLUIS_PREVIOUS_MASTER_KEYS', ''))
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

];
