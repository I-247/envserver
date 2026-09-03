export type TeamRole = 'owner' | 'admin' | 'member' | 'viewer';

export type Team = {
    id: number;
    name: string;
    slug: string;
    isPersonal: boolean;
    /** IP addresses and CIDR ranges the team may be reached from; empty means no restriction. */
    ipAllowList?: string[];
    /** Whether members need an authenticator app or a passkey to reach this team. */
    twoFactorRequired?: boolean;
    /** How many members are not enrolled in either yet. */
    membersWithoutSecondFactor?: number;
    /** Days a secret may stand before it is reported; null means no policy. */
    defaultRotateAfterDays?: number | null;
    role?: TeamRole;
    roleLabel?: string;
    isCurrent?: boolean;
};

export type TeamMember = {
    id: number;
    name: string;
    email: string;
    avatar?: string | null;
    role: TeamRole;
    role_label: string;
};

export type TeamInvitation = {
    code: string;
    email: string;
    role: TeamRole;
    role_label: string;
    created_at: string;
};

export type TeamInvitationContext = {
    code: string;
    teamName: string;
};

export type DashboardInvitation = {
    code: string;
    inviterName: string;
    team: {
        name: string;
        slug: string;
    };
};

export type TeamPermissions = {
    canUpdateTeam: boolean;
    canDeleteTeam: boolean;
    canAddMember: boolean;
    canUpdateMember: boolean;
    canRemoveMember: boolean;
    canCreateInvitation: boolean;
    canCancelInvitation: boolean;
};

export type EnvserverPermissions = {
    canCreateProject: boolean;
    canUpdateProject: boolean;
    canDeleteProject: boolean;
    canManageVariable: boolean;
    canViewSecretValue: boolean;
    canPublishRelease: boolean;
    canManageDeployToken: boolean;
};

export type RoleOption = {
    value: TeamRole;
    label: string;
};

export type WebhookOption = {
    value: string;
    label: string;
};

export type WebhookEndpointSummary = {
    id: number;
    name: string;
    kind: string;
    kindLabel: string;
    /** Host plus a trimmed path: the full URL is a credential for Slack. */
    url: string;
    /** Audit actions this endpoint asked for; empty means every one. */
    events: string[];
    active: boolean;
    lastAttemptedAt: string | null;
    lastStatus: number | null;
    lastError: string | null;
    consecutiveFailures: number;
};
