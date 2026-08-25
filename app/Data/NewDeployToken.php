<?php

namespace App\Data;

use App\Models\DeployToken;

/**
 * A freshly created deploy token, including the secret.
 *
 * The secret is only ever available here, right after creation. It is never
 * stored in a readable form, so if the user loses it they issue a new token.
 */
final readonly class NewDeployToken
{
    public function __construct(
        public DeployToken $model,
        public string $clientId,
        public string $clientSecret,
    ) {}
}
