<?php

namespace App\Http\Middleware;

use App\Models\DeployToken;
use Closure;
use Illuminate\Http\Request;
use Laravel\Passport\AccessToken;
use Laravel\Passport\Contracts\ScopeAuthorizable;
use Laravel\Passport\Exceptions\AuthenticationException;
use Laravel\Passport\Exceptions\MissingScopeException;
use Laravel\Passport\Http\Middleware\ValidateToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turns a client credentials access token into the one environment it may see.
 *
 * OAuth has already established "this is client X holding scope Y". It cannot
 * answer the two questions that matter here: which environment does client X
 * stand for, and was client X actually granted scope Y when it was issued.
 * Passport will hand any registered scope to any client on request, so the
 * allow list on the deploy token is what keeps a read only token read only.
 */
class ResolveDeployToken extends ValidateToken
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$params): Response
    {
        $accessToken = $this->validateToken($request);

        $this->validate($accessToken, ...$params);

        $deployToken = DeployToken::with('environment.project.team')
            ->where('oauth_client_id', $this->attribute($accessToken, 'oauth_client_id'))
            ->first();

        abort_if($deployToken === null || ! $deployToken->isUsable(), 403);

        foreach ($params as $scope) {
            abort_unless($deployToken->allows($scope), 403);
        }

        $deployToken->markUsed();

        $request->attributes->set('deploy_token', $deployToken);

        return $next($request);
    }

    /**
     * Reject anything that is not a machine token, or that lacks a scope.
     */
    protected function validate(ScopeAuthorizable $token, string ...$params): void
    {
        $userId = $this->attribute($token, 'oauth_user_id');

        if ($userId !== null && $userId !== $this->attribute($token, 'oauth_client_id')) {
            throw new AuthenticationException;
        }

        foreach ($params as $scope) {
            if ($token->cant($scope)) {
                throw new MissingScopeException($scope);
            }
        }
    }

    /**
     * Read a claim off the token.
     *
     * Read from the attribute bag rather than the magic property: a client
     * credentials token carries no user, which the property's PHPDoc does not
     * admit to.
     */
    private function attribute(ScopeAuthorizable $token, string $key): ?string
    {
        if (! $token instanceof AccessToken) {
            throw new AuthenticationException;
        }

        $value = $token->toArray()[$key] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }
}
