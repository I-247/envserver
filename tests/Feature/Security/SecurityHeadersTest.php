<?php

use App\Providers\AppServiceProvider;

it('sends security headers on every response', function (string $path) {
    $this->get($path)
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'same-origin')
        ->assertHeader('Content-Security-Policy', "frame-ancestors 'none'; object-src 'none'; base-uri 'self'; form-action 'self'");
})->with(['page' => '/login', 'api' => '/api/v1/cli', 'missing' => '/does-not-exist']);

it('only asks for HSTS over https', function () {
    $this->get('http://localhost/login')->assertHeaderMissing('Strict-Transport-Security');

    $this->get('https://localhost/login')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});

it('switches debug off in production whatever the env says', function () {
    config(['app.debug' => true]);
    app()->detectEnvironment(fn () => 'production');

    (new AppServiceProvider(app()))->boot();

    expect(config('app.debug'))->toBeFalse();
});
