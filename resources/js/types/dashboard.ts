export type DashboardStats = {
    projects: number;
    environments: number;
    variables: number;
    deployTokens: number;
};

export type PendingEnvironment = {
    project: { name: string; slug: string };
    environment: { name: string; slug: string };
    changes: number;
    version: number | null;
};

export type DashboardRelease = {
    id: number;
    version: number;
    message: string | null;
    project: { name: string; slug: string };
    environment: { name: string; slug: string };
    publishedBy: string | null;
    publishedAt: string | null;
    variablesCount: number;
};

export type DashboardDeployToken = {
    name: string;
    project: string;
    environment: string;
    lastUsedAt: string | null;
    expiresAt: string | null;
};

export type DashboardActivity = {
    id: number;
    label: string;
    actor: string | null;
    createdAt: string | null;
};

export type DashboardEncryption = {
    cipher: string;
    scheme: string;
    keyVersion: number | null;
    keyCreatedAt: string | null;
};

export type StaleSecret = {
    key: string;
    /** The project answering for the variable, when one owns it. */
    project: string | null;
    overdueByDays: number;
    intervalDays: number | null;
    rotatedAt: string | null;
};

export type DashboardStaleSecrets = {
    /** Every overdue secret, not only the ones listed below. */
    total: number;
    rows: StaleSecret[];
};
