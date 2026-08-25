<?php

namespace App\Enums;

enum AuditAction: string
{
    case VariableCreated = 'variable.created';
    case VariableUpdated = 'variable.updated';
    case VariableDetached = 'variable.detached';

    case SecretRevealed = 'secret.revealed';

    case ReleasePublished = 'release.published';
    case ReleaseRolledBack = 'release.rolled-back';

    case DeployTokenCreated = 'deploy-token.created';
    case DeployTokenRevoked = 'deploy-token.revoked';

    /**
     * Get the human readable label for the action.
     */
    public function label(): string
    {
        return match ($this) {
            self::VariableCreated => 'Variable created',
            self::VariableUpdated => 'Variable changed',
            self::VariableDetached => 'Variable removed from environment',
            self::SecretRevealed => 'Secret revealed',
            self::ReleasePublished => 'Release published',
            self::ReleaseRolledBack => 'Rolled back',
            self::DeployTokenCreated => 'Deploy token created',
            self::DeployTokenRevoked => 'Deploy token revoked',
        };
    }
}
