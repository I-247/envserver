import { Head, Link, usePage } from '@inertiajs/react';
import { ShieldCheck, Zap } from 'lucide-react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import environments from '@/routes/environments';
import { index, show } from '@/routes/projects';
import type { ProjectDetail } from '@/types';

type Props = {
    project: ProjectDetail;
    permissions: {
        canUpdateProject: boolean;
        canDeleteProject: boolean;
    };
};

export default function ProjectShow({ project }: Props) {
    const page = usePage();
    const teamSlug = page.props.currentTeam?.slug ?? '';

    return (
        <>
            <Head title={project.name} />

            <div className="flex flex-col space-y-6 p-4">
                <Heading
                    variant="small"
                    title={project.name}
                    description={project.description ?? project.slug}
                />

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
                            <code className="text-xs text-muted-foreground">
                                {teamSlug}/{project.slug}/{environment.slug}
                            </code>
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
