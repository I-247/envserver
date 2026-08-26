import { Head, Link, usePage } from '@inertiajs/react';
import { Boxes, Plus, Search } from 'lucide-react';
import { useMemo, useState } from 'react';
import CreateProjectModal from '@/components/create-project-modal';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import environments from '@/routes/environments';
import { index, show } from '@/routes/projects';
import type { ProjectSummary } from '@/types';

type Props = {
    projects: ProjectSummary[];
    permissions: {
        canCreateProject: boolean;
    };
};

/**
 * A deploy token's use is the moment a server actually pulled this project's
 * variables, which is as close as we get to a deploy.
 */
function formatDeploys(count: number, lastDeployedAt: string | null): string {
    if (!lastDeployedAt) {
        return 'Never deployed';
    }

    const moment = new Date(lastDeployedAt).toLocaleString(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    });

    // Deploys from before the counter existed are not in the count, so a
    // project can have a deploy moment without a number to go with it.
    if (count === 0) {
        return `Deployed ${moment}`;
    }

    return `${count} ${count === 1 ? 'deploy' : 'deploys'} · last ${moment}`;
}

export default function ProjectsIndex({ projects, permissions }: Props) {
    const page = usePage();
    const teamSlug = page.props.currentTeam?.slug ?? '';
    const [search, setSearch] = useState('');

    const filteredProjects = useMemo(() => {
        const needle = search.trim().toLowerCase();

        if (needle === '') {
            return projects;
        }

        return projects.filter((project) =>
            [
                project.name,
                project.slug,
                project.description ?? '',
                ...project.environments.map((environment) => environment.name),
            ]
                .join(' ')
                .toLowerCase()
                .includes(needle),
        );
    }, [projects, search]);

    return (
        <>
            <Head title="Projects" />

            <div className="flex flex-col space-y-6 p-4">
                <div className="flex items-center justify-between">
                    <Heading
                        variant="small"
                        title="Projects"
                        description="Every project keeps its own environments and variables"
                    />

                    {permissions.canCreateProject ? (
                        <CreateProjectModal teamSlug={teamSlug}>
                            <Button data-test="projects-new-button">
                                <Plus /> New project
                            </Button>
                        </CreateProjectModal>
                    ) : null}
                </div>

                {projects.length === 0 ? (
                    <div className="flex flex-col items-center gap-2 rounded-lg border border-dashed p-12 text-center">
                        <Boxes className="size-8 text-muted-foreground" />
                        <p className="font-medium">No projects yet</p>
                        <p className="text-sm text-muted-foreground">
                            Create a project to start storing environment
                            variables.
                        </p>
                    </div>
                ) : (
                    <div className="space-y-3">
                        <div className="relative">
                            <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                type="search"
                                value={search}
                                onChange={(event) =>
                                    setSearch(event.target.value)
                                }
                                placeholder="Search projects..."
                                aria-label="Search projects"
                                data-test="projects-search"
                                className="pl-9"
                            />
                        </div>

                        {filteredProjects.length === 0 ? (
                            <div
                                className="rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground"
                                data-test="projects-empty-search"
                            >
                                No projects match &ldquo;{search}&rdquo;.
                            </div>
                        ) : (
                            filteredProjects.map((project) => (
                                <div
                                    key={project.slug}
                                    data-test="project-row"
                                    className="relative flex items-start justify-between gap-4 rounded-lg border p-4 transition-colors hover:bg-accent"
                                >
                                    {/* Covers the whole card so it stays one
                                        click target, while the environment
                                        links sit on top of it. Nesting them
                                        inside an anchor would be invalid. */}
                                    <Link
                                        href={show([teamSlug, project.slug])}
                                        className="absolute inset-0 rounded-lg"
                                        data-test="project-link"
                                    >
                                        <span className="sr-only">
                                            Open {project.name}
                                        </span>
                                    </Link>

                                    <div className="space-y-2">
                                        <div>
                                            <span className="font-medium">
                                                {project.name}
                                            </span>
                                            <p className="text-sm text-muted-foreground">
                                                {project.description ??
                                                    project.slug}
                                            </p>
                                        </div>

                                        {project.environments.length === 0 ? (
                                            <p
                                                className="text-xs text-muted-foreground"
                                                data-test="project-environments-empty"
                                            >
                                                No environments yet
                                            </p>
                                        ) : (
                                            <div className="relative z-10 flex flex-wrap gap-1">
                                                {project.environments.map(
                                                    (environment) => (
                                                        <Badge
                                                            key={
                                                                environment.slug
                                                            }
                                                            variant="secondary"
                                                            asChild
                                                            data-test="project-environment-badge"
                                                        >
                                                            <Link
                                                                href={environments.show(
                                                                    [
                                                                        teamSlug,
                                                                        project.slug,
                                                                        environment.slug,
                                                                    ],
                                                                )}
                                                            >
                                                                {
                                                                    environment.name
                                                                }
                                                            </Link>
                                                        </Badge>
                                                    ),
                                                )}
                                            </div>
                                        )}
                                    </div>
                                    <span className="text-sm text-muted-foreground">
                                        {formatDeploys(
                                            project.deployCount,
                                            project.lastDeployedAt,
                                        )}
                                    </span>
                                </div>
                            ))
                        )}
                    </div>
                )}
            </div>
        </>
    );
}

ProjectsIndex.layout = (props: { currentTeam?: { slug: string } | null }) => ({
    breadcrumbs: [
        {
            title: 'Projects',
            href: props.currentTeam ? index(props.currentTeam.slug) : '/',
        },
    ],
});
