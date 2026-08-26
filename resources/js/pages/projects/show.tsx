import { Head, Link, usePage } from '@inertiajs/react';
import {
    Columns3,
    KeyRound,
    Pencil,
    Plus,
    ShieldCheck,
    Trash2,
    Zap,
} from 'lucide-react';
import CreateEnvironmentModal from '@/components/create-environment-modal';
import DeleteProjectModal from '@/components/delete-project-modal';
import EditProjectModal from '@/components/edit-project-modal';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import environments from '@/routes/environments';
import { drift, index, show } from '@/routes/projects';
import type { ProjectDetail } from '@/types';

type Props = {
    project: ProjectDetail;
    permissions: {
        canUpdateProject: boolean;
        canDeleteProject: boolean;
    };
};

export default function ProjectShow({ project, permissions }: Props) {
    const page = usePage();
    const teamSlug = page.props.currentTeam?.slug ?? '';

    return (
        <>
            <Head title={project.name} />

            <div className="flex flex-col space-y-6 p-4">
                <div className="flex items-center justify-between">
                    <Heading
                        variant="small"
                        title={project.name}
                        description={project.description ?? project.slug}
                    />

                    <div className="flex items-center gap-2">
                        <Button variant="outline" asChild>
                            <Link
                                href={drift([teamSlug, project.slug])}
                                data-test="project-drift-link"
                            >
                                <Columns3 /> Compare environments
                            </Link>
                        </Button>

                        {permissions.canUpdateProject ? (
                            <CreateEnvironmentModal
                                teamSlug={teamSlug}
                                projectSlug={project.slug}
                            >
                                <Button data-test="environment-new-button">
                                    <Plus /> New environment
                                </Button>
                            </CreateEnvironmentModal>
                        ) : null}

                        {permissions.canUpdateProject ? (
                            <EditProjectModal
                                teamSlug={teamSlug}
                                project={project}
                            >
                                <Button
                                    variant="outline"
                                    data-test="project-edit-button"
                                >
                                    <Pencil /> Edit project
                                </Button>
                            </EditProjectModal>
                        ) : null}

                        {permissions.canDeleteProject ? (
                            <DeleteProjectModal
                                teamSlug={teamSlug}
                                project={project}
                            >
                                <Button
                                    variant="outline"
                                    data-test="project-delete-button"
                                    className="text-destructive hover:text-destructive"
                                >
                                    <Trash2 /> Delete
                                </Button>
                            </DeleteProjectModal>
                        ) : null}
                    </div>
                </div>

                <div className="grid gap-3 md:grid-cols-3">
                    {project.environments.map((environment) => (
                        <Link
                            key={environment.slug}
                            href={environments.show([
                                teamSlug,
                                project.slug,
                                environment.slug,
                            ])}
                            data-test="environment-card"
                            className="flex flex-col gap-2 rounded-lg border p-4 transition-colors hover:bg-accent"
                        >
                            <div className="flex items-center justify-between">
                                <span className="font-medium">
                                    {environment.name}
                                </span>
                                <Badge
                                    variant={
                                        environment.autoPublish
                                            ? 'secondary'
                                            : 'outline'
                                    }
                                >
                                    {environment.autoPublish ? (
                                        <>
                                            <Zap className="size-3" /> Auto
                                            publish
                                        </>
                                    ) : (
                                        <>
                                            <ShieldCheck className="size-3" />{' '}
                                            Manual
                                        </>
                                    )}
                                </Badge>
                            </div>
                            <div className="flex items-center justify-between gap-2">
                                <code className="text-xs text-muted-foreground">
                                    {teamSlug}/{project.slug}/{environment.slug}
                                </code>
                                <span
                                    className="flex shrink-0 items-center gap-1 text-xs text-muted-foreground"
                                    data-test="environment-variable-count"
                                >
                                    <KeyRound className="size-3" />
                                    {environment.variableCount}{' '}
                                    {environment.variableCount === 1
                                        ? 'secret'
                                        : 'secrets'}
                                </span>
                            </div>
                        </Link>
                    ))}
                </div>
            </div>
        </>
    );
}

ProjectShow.layout = (props: {
    currentTeam?: { slug: string } | null;
    project: ProjectDetail;
}) => ({
    breadcrumbs: [
        {
            title: 'Projects',
            href: props.currentTeam ? index(props.currentTeam.slug) : '/',
        },
        {
            title: props.project.name,
            href: props.currentTeam
                ? show([props.currentTeam.slug, props.project.slug])
                : '/',
        },
    ],
});
