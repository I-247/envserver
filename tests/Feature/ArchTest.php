<?php

arch('debug helpers never reach the repository')
    ->expect(['dd', 'ddd', 'dump', 'ray', 'var_dump', 'print_r'])
    ->not->toBeUsed();

arch('configuration is read through config(), never env()')
    ->expect('env')
    ->not->toBeUsed();

arch('only the cryptography layer touches the master key')
    ->expect('App\Cryptography\MasterKeyProvider')
    ->toOnlyBeUsedIn('App\Cryptography');

arch('the cryptography layer knows nothing about HTTP')
    ->expect('App\Cryptography')
    ->not->toUse(['App\Http', 'Illuminate\Http']);

arch('controllers never encrypt or decrypt directly, they go through actions')
    ->expect('App\Http\Controllers')
    ->not->toUse(['App\Cryptography\TeamKeyManager', 'App\Contracts\SecretCipher']);

arch('actions are single purpose classes')
    ->expect('App\Actions')
    ->toHaveMethod('handle')
    ->ignoring('App\Actions\Fortify');

arch('models stay out of the HTTP layer')
    ->expect('App\Models')
    ->not->toUse('App\Http');

arch('enums are backed so they can be persisted and sent over the wire')
    ->expect('App\Enums')
    ->toBeStringBackedEnums();
