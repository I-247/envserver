export type ProjectEnvironmentSummary = {
    name: string;
    slug: string;
};

export type ProjectSummary = {
    name: string;
    slug: string;
    description: string | null;
    environments: ProjectEnvironmentSummary[];
    deployCount: number;
    lastDeployedAt: string | null;
};

export type EnvironmentSummary = {
    name: string;
    slug: string;
    autoPublish: boolean;
    /** Number of variables assigned to this environment. */
    variableCount: number;
    /** IP addresses and CIDR ranges deploy tokens may pull from; empty means no restriction. */
    ipAllowList?: string[];
};

export type ProjectDetail = {
    name: string;
    slug: string;
    description: string | null;
    environments: EnvironmentSummary[];
};

export type VariableOwner = {
    name: string;
    slug: string;
};

export type EnvironmentVariable = {
    id: number;
    key: string;
    ownKey: string;
    alias: string | null;
    description: string | null;
    shared: boolean;
    sharedWith: number;
    /** The project responsible for the value, which may not be this one. */
    owner: VariableOwner | null;
    /** True when another project owns this variable and we only read it. */
    borrowed: boolean;
    /** True when the owner offers this variable to the team's projects. */
    shareable: boolean;
    /** True when this project owns it and may change the offer. */
    canOffer: boolean;
    version: number;
    updatedAt: string | null;
    rotation: VariableRotation;
};

export type VariableRotation = {
    /** The interval that actually applies, from the variable or the team; null means no policy. */
    intervalDays: number | null;
    /** The interval this variable set for itself, if any. */
    ownIntervalDays: number | null;
    dueAt: string | null;
    /** Days past the interval, zero when the value is still within it. */
    overdueByDays: number;
};

export type ShareableVariable = {
    id: number;
    key: string;
    description: string | null;
    project: string;
    projectSlug: string;
    sharedWith: number;
};

export type PendingChange = {
    key: string;
    type: 'added' | 'removed' | 'changed';
    before: string | null;
    after: string | null;
};

export type EnvironmentPermissions = {
    canManageEnvironment: boolean;
    canDeleteEnvironment: boolean;
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

export type DriftEnvironment = {
    name: string;
    slug: string;
    /** True when the environment publishes on purpose rather than automatically. */
    guarded: boolean;
};

export type DriftEntry = {
    key: string;
    /** Environment slug to value group; null when that environment lacks the key. Equal numbers mean equal values. */
    groups: Record<string, number | null>;
    missingIn: string[];
    /** Environments running the same value as a guarded environment. */
    reusedIn: string[];
    differs: boolean;
};
