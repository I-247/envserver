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
