<?php

use App\Providers\AppServiceProvider;

it('sends security headers on every response', function (string $path) {
    $this->get($path)
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'same-origin')
        ->assertHeader('Content-Security-Policy');

    expect($this->get($path)->headers->get('Content-Security-Policy'))
        ->toStartWith("frame-ancestors 'none'; object-src 'none'; base-uri 'self'; form-action 'self'");
})->with(['page' => '/login', 'api' => '/api/v1/cli', 'missing' => '/does-not-exist']);

it('only lets scripts with this response\'s nonce run on app pages', function () {
    $response = $this->get('/login');
    $policy = $response->headers->get('Content-Security-Policy');

    expect($policy)->toMatch("/script-src 'nonce-([A-Za-z0-9]+)' 'strict-dynamic' 'self'/");

    preg_match("/'nonce-([A-Za-z0-9]+)'/", $policy, $nonce);
    preg_match_all('/<script(?![^>]*type="application\/json")[^>]*>/', $response->getContent(), $scripts);

    expect($scripts[0])->not->toBeEmpty();

    foreach ($scripts[0] as $tag) {
        expect($tag)->toContain('nonce="'.$nonce[1].'"');
    }

    expect($this->get('/login')->headers->get('Content-Security-Policy'))->not->toContain($nonce[1]);
});

it('leaves script sources alone where the page is not the app', function () {
    expect($this->getJson('/api/v1/cli')->headers->get('Content-Security-Policy'))->not->toContain('script-src');
});

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
