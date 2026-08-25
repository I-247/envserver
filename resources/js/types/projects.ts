export type ProjectSummary = {
    name: string;
    slug: string;
    description: string | null;
    environmentsCount: number;
};

export type EnvironmentSummary = {
    name: string;
    slug: string;
    autoPublish: boolean;
};

export type ProjectDetail = {
    name: string;
    slug: string;
    description: string | null;
    environments: EnvironmentSummary[];
};

export type EnvironmentVariable = {
    id: number;
    key: string;
    ownKey: string;
    alias: string | null;
    description: string | null;
    shared: boolean;
    sharedWith: number;
    version: number;
    updatedAt: string | null;
};

export type PendingChange = {
    key: string;
    type: 'added' | 'removed' | 'changed';
    before: string | null;
    after: string | null;
};

export type EnvironmentPermissions = {
    canManageVariable: boolean;
    canViewSecretValue: boolean;
    canPublishRelease: boolean;
    canManageDeployToken: boolean;
};

export type ReleaseSummary = {
    id: number;
    version: number;
    message: string | null;
    publishedBy: string | null;
    publishedAt: string | null;
    variablesCount: number;
};
