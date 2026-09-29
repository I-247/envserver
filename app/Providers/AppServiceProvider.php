<?php

namespace App\Providers;

use App\Actions\Releases\PublishAutomaticReleases;
use App\Contracts\SecretCipher;
use App\Cryptography\AesGcmSecretCipher;
use App\Enums\ApiScope;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Laravel\Passport\Passport;
use Laravel\Passport\Scope;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(SecretCipher::class, AesGcmSecretCipher::class);

        // Shared so that an open batch is visible to the actions nested inside
        // it: without one instance, PushVariables would hold back releases that
        // UpdateVariableValue's own copy of the action happily publishes.
        $this->app->scoped(PublishAutomaticReleases::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configurePassport();
        $this->configureRateLimiting();
    }

    /**
     * Limit how fast one caller can use the API.
     *
     * Keyed on the user for a personal token and on the address otherwise,
     * which is what a deploy token presents before it is resolved.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(config('envserver.api_requests_per_minute'))
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));
    }

    /**
     * Configure the OAuth scopes the API understands.
     *
     * Registering them is what lets Passport reject a token request for a
     * scope this application does not have.
     */
    protected function configurePassport(): void
    {
        Passport::tokensCan(ApiScope::map());

        $lifetime = CarbonInterval::days(config('envserver.api_token_days'));

        Passport::tokensExpireIn($lifetime);
        Passport::refreshTokensExpireIn($lifetime);
        Passport::personalAccessTokensExpireIn($lifetime);

        Passport::deviceUserCodeView(
            fn (array $parameters) => Inertia::render('auth/device/user-code', [
                'request' => $parameters['request']->all(),
            ])->toResponse(request()),
        );

        Passport::deviceAuthorizationView(
            fn (array $parameters) => Inertia::render('auth/device/authorize', [
                'authToken' => $parameters['authToken'],
                'client' => ['name' => $parameters['client']->name],
                'scopes' => array_map(
                    fn (Scope $scope) => ['id' => $scope->id, 'description' => $scope->description],
                    $parameters['scopes'],
                ),
            ])->toResponse(request()),
        );
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        // A debug error page prints the environment, master key included. A
        // production .env copied from .env.example must not be able to do that.
        if (app()->isProduction() && config('app.debug')) {
            config(['app.debug' => false]);

            Log::warning('APP_DEBUG is on in production; it has been switched off. Set APP_DEBUG=false.');
        }

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
