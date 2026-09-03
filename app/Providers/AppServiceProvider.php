<?php

namespace App\Providers;

use App\Actions\Releases\PublishAutomaticReleases;
use App\Contracts\SecretCipher;
use App\Cryptography\AesGcmSecretCipher;
use App\Enums\ApiScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
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
