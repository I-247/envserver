<?php

namespace App\Enums;

enum AuditAction: string
{
    case ProjectDeleted = 'project.deleted';

    case EnvironmentCreated = 'environment.created';
    case EnvironmentUpdated = 'environment.updated';
    case EnvironmentDeleted = 'environment.deleted';

    case VariableCreated = 'variable.created';
    case VariableUpdated = 'variable.updated';
    case VariableDetached = 'variable.detached';
    case VariableOffered = 'variable.offered';
    case VariableWithdrawn = 'variable.withdrawn';
    case VariableShared = 'variable.shared';
    case VariableOwnershipTransferred = 'variable.ownership-transferred';

    case SecretRevealed = 'secret.revealed';
    case EnvFileDownloaded = 'env-file.downloaded';

    case ReleasePublished = 'release.published';
    case ReleaseRolledBack = 'release.rolled-back';

    case DeployTokenCreated = 'deploy-token.created';
    case DeployTokenRevoked = 'deploy-token.revoked';
    case DeployTokenBlocked = 'deploy-token.blocked';
    case DeployTokenPushed = 'deploy-token.pushed';

    case TeamIpAllowListUpdated = 'team.ip-allowlist-updated';
    case TeamTwoFactorRequirementUpdated = 'team.two-factor-requirement-updated';
    case TeamRotationPolicyUpdated = 'team.rotation-policy-updated';

    case WebhookEndpointCreated = 'webhook-endpoint.created';
    case WebhookEndpointDeleted = 'webhook-endpoint.deleted';

    /**
     * Get the human readable label for the action.
     */
    public function label(): string
    {
        return match ($this) {
            self::ProjectDeleted => 'Project deleted',
            self::EnvironmentCreated => 'Environment created',
            self::EnvironmentUpdated => 'Environment changed',
            self::EnvironmentDeleted => 'Environment deleted',
            self::VariableCreated => 'Variable created',
            self::VariableUpdated => 'Variable changed',
            self::VariableDetached => 'Variable removed from environment',
            self::VariableOffered => 'Variable offered for sharing',
            self::VariableWithdrawn => 'Variable no longer offered for sharing',
            self::VariableShared => 'Variable shared with another project',
            self::VariableOwnershipTransferred => 'Variable ownership transferred',
            self::SecretRevealed => 'Secret revealed',
            self::EnvFileDownloaded => 'Environment file downloaded',
            self::ReleasePublished => 'Release published',
            self::ReleaseRolledBack => 'Rolled back',
            self::DeployTokenCreated => 'Deploy token created',
            self::DeployTokenRevoked => 'Deploy token revoked',
            self::DeployTokenBlocked => 'Deploy token blocked by IP allow list',
            self::DeployTokenPushed => 'Variables pushed by deploy token',
            self::TeamIpAllowListUpdated => 'Team IP allow list changed',
            self::TeamTwoFactorRequirementUpdated => 'Team two-factor requirement changed',
            self::TeamRotationPolicyUpdated => 'Team rotation policy changed',
            self::WebhookEndpointCreated => 'Webhook endpoint added',
            self::WebhookEndpointDeleted => 'Webhook endpoint removed',
        };
    }
}
