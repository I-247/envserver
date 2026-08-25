import { Head, Link, usePage } from '@inertiajs/react';
import { Boxes, Plus } from 'lucide-react';
import CreateProjectModal from '@/components/create-project-modal';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { index, show } from '@/routes/projects';
import type { ProjectSummary } from '@/types';

type Props = {
    projects: ProjectSummary[];
    permissions: {
        canCreateProject: boolean;
    };
};

export default function ProjectsIndex({ projects, permissions }: Props) {
    const page = usePage();
    const teamSlug = page.props.currentTeam?.slug ?? '';

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
                        {projects.map((project) => (
                            <Link
                                key={project.slug}
                                href={show([teamSlug, project.slug])}
                                data-test="project-row"
                                className="flex items-center justify-between gap-4 rounded-lg border p-4 transition-colors hover:bg-accent"
                            >
                                <div>
                                    <span className="font-medium">
                                        {project.name}
                                    </span>
                                    <p className="text-sm text-muted-foreground">
                                        {project.description ?? project.slug}
                                    </p>
                                </div>
                                <span className="text-sm text-muted-foreground">
                                    {project.environmentsCount} environments
                                </span>
                            </Link>
                        ))}
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
