import { Form, Head, usePage } from '@inertiajs/react';
import { Undo2 } from 'lucide-react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import environments, { show as environmentShow } from '@/routes/environments';
import { index as projectsIndex, show as projectShow } from '@/routes/projects';
import type { ReleaseSummary } from '@/types';

type Props = {
    project: { name: string; slug: string };
    environment: { name: string; slug: string };
    releases: ReleaseSummary[];
    permissions: { canPublishRelease: boolean };
};

export default function EnvironmentReleases({
    project,
    environment,
    releases,
    permissions,
}: Props) {
    const page = usePage();
    const teamSlug = page.props.currentTeam?.slug ?? '';
    const args: [string, string, string] = [
        teamSlug,
        project.slug,
        environment.slug,
    ];

    return (
        <>
            <Head title={`${environment.name} releases`} />

            <div className="flex flex-col space-y-6 p-4">
                <Heading
                    variant="small"
                    title="Release history"
                    description="Every release pins the exact values it shipped, so rolling back is a lookup rather than a reconstruction."
                />

                {releases.length === 0 ? (
                    <div className="rounded-lg border border-dashed p-12 text-center">
                        <p className="font-medium">No releases yet</p>
                    </div>
                ) : (
                    <div className="space-y-3">
                        {releases.map((release, index) => (
                            <div
                                key={release.id}
                                data-test="release-row"
                                className="flex flex-wrap items-center justify-between gap-3 rounded-lg border p-4"
                            >
                                <div>
                                    <div className="flex items-center gap-2">
                                        <span className="font-medium">
                                            Release {release.version}
                                        </span>
                                        {index === 0 ? (
                                            <span className="text-xs text-muted-foreground">
                                                live
                                            </span>
                                        ) : null}
                                    </div>
                                    <p className="text-sm text-muted-foreground">
                                        {release.message ?? 'No message'} ·{' '}
                                        {release.variablesCount} variables
                                        {release.publishedBy
                                            ? ` · ${release.publishedBy}`
                                            : ''}
                                    </p>
                                </div>

                                {permissions.canPublishRelease && index > 0 ? (
                                    <Form
                                        {...environments.releases.rollback.form(
                                            [...args, release.id],
                                        )}
                                    >
                                        {({ processing }) => (
                                            <Button
                                                type="submit"
                                                variant="secondary"
                                                disabled={processing}
                                                data-test="rollback-release"
                                            >
                                                <Undo2 /> Roll back to this
                                            </Button>
                                        )}
                                    </Form>
                                ) : null}
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}

EnvironmentReleases.layout = (
    props: Props & {
        currentTeam?: { slug: string } | null;
    },
) => {
    const team = props.currentTeam?.slug ?? '';

    return {
        breadcrumbs: [
            { title: 'Projects', href: projectsIndex(team) },
            {
                title: props.project.name,
                href: projectShow([team, props.project.slug]),
            },
            {
                title: props.environment.name,
                href: environmentShow([
                    team,
                    props.project.slug,
                    props.environment.slug,
                ]),
            },
            {
                title: 'Releases',
                href: environments.releases.index([
                    team,
                    props.project.slug,
                    props.environment.slug,
                ]),
            },
        ],
    };
};
