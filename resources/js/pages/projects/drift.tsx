import { Head } from '@inertiajs/react';
import { CircleAlert, Search, TriangleAlert } from 'lucide-react';
import { useMemo, useState } from 'react';
import Code from '@/components/code';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Toggle } from '@/components/ui/toggle';
import { cn } from '@/lib/utils';
import { drift, index, show } from '@/routes/projects';
import type { DriftEntry, DriftEnvironment, ProjectSummary } from '@/types';

type Props = {
    project: Pick<ProjectSummary, 'name' | 'slug'>;
    environments: DriftEnvironment[];
    entries: DriftEntry[];
    summary: {
        keys: number;
        missing: number;
        reused: number;
    };
};

/**
 * The label a value group is shown under.
 *
 * Letters rather than numbers: two cells reading "A" say "same value" at a
 * glance, which is the entire question this table answers, and a letter is
 * harder to mistake for a version number than a digit would be.
 */
function groupLabel(group: number): string {
    return String.fromCharCode(64 + group);
}

function GroupChip({ group, muted }: { group: number; muted: boolean }) {
    return (
        <span
            className={cn(
                'inline-flex size-6 items-center justify-center rounded text-xs font-medium',
                muted
                    ? 'bg-muted text-muted-foreground'
                    : 'bg-primary/10 text-primary',
            )}
        >
            {groupLabel(group)}
        </span>
    );
}

export default function ProjectDrift({
    project,
    environments,
    entries,
    summary,
}: Props) {
    const [search, setSearch] = useState('');
    const [onlyProblems, setOnlyProblems] = useState(false);

    const visible = useMemo(() => {
        const term = search.trim().toLowerCase();

        return entries.filter((entry) => {
            if (term && !entry.key.toLowerCase().includes(term)) {
                return false;
            }

            return onlyProblems
                ? entry.missingIn.length > 0 || entry.reusedIn.length > 0
                : true;
        });
    }, [entries, search, onlyProblems]);

    return (
        <>
            <Head title={`${project.name} · Drift`} />

            <div className="flex flex-col space-y-6 p-4">
                <Heading
                    variant="small"
                    title="Environment drift"
                    description="Which keys each environment exposes, and where two of them run the same value. No values are shown on this page."
                />

                <div className="grid gap-3 sm:grid-cols-3">
                    <div className="rounded-lg border p-4">
                        <div className="text-2xl font-semibold">
                            {summary.keys}
                        </div>
                        <div className="text-sm text-muted-foreground">
                            {summary.keys === 1 ? 'key' : 'keys'} across{' '}
                            {environments.length}{' '}
                            {environments.length === 1
                                ? 'environment'
                                : 'environments'}
                        </div>
                    </div>

                    <div className="rounded-lg border p-4">
                        <div
                            className="text-2xl font-semibold"
                            data-test="drift-missing-count"
                        >
                            {summary.missing}
                        </div>
                        <div className="text-sm text-muted-foreground">
                            missing somewhere
                        </div>
                    </div>

                    <div className="rounded-lg border p-4">
                        <div
                            className="text-2xl font-semibold"
                            data-test="drift-reused-count"
                        >
                            {summary.reused}
                        </div>
                        <div className="text-sm text-muted-foreground">
                            shared with a manually published environment
                        </div>
                    </div>
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <div className="relative flex-1 sm:max-w-xs">
                        <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            type="search"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Search keys..."
                            aria-label="Search keys"
                            data-test="drift-search"
                            className="pl-9"
                        />
                    </div>

                    <Toggle
                        pressed={onlyProblems}
                        onPressedChange={setOnlyProblems}
                        variant="outline"
                        data-test="drift-only-problems"
                    >
                        <TriangleAlert className="size-4" />
                        Only differences
                    </Toggle>
                </div>

                {visible.length === 0 ? (
                    <div
                        className="rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground"
                        data-test="drift-empty"
                    >
                        {entries.length === 0
                            ? 'This project has no variables yet.'
                            : 'Nothing matches the current filter.'}
                    </div>
                ) : (
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-left text-sm">
                            <thead className="border-b bg-muted/40">
                                <tr>
                                    <th className="p-3 font-medium">Key</th>
                                    {environments.map((environment) => (
                                        <th
                                            key={environment.slug}
                                            className="p-3 font-medium whitespace-nowrap"
                                        >
                                            {environment.name}
                                            {environment.guarded ? (
                                                <span className="ml-1 text-xs font-normal text-muted-foreground">
                                                    manual
                                                </span>
                                            ) : null}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {visible.map((entry) => (
                                    <tr
                                        key={entry.key}
                                        className="border-b last:border-0"
                                        data-test="drift-row"
                                    >
                                        <td className="p-3">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <Code>{entry.key}</Code>

                                                {entry.reusedIn.length > 0 ? (
                                                    <Badge
                                                        variant="outline"
                                                        className="text-destructive"
                                                        data-test="drift-reused-badge"
                                                        title={`The same value runs in ${entry.reusedIn.join(', ')}`}
                                                    >
                                                        <CircleAlert className="size-3" />
                                                        Same value in{' '}
                                                        {entry.reusedIn.length}
                                                    </Badge>
                                                ) : null}
                                            </div>
                                        </td>

                                        {environments.map((environment) => {
                                            const group =
                                                entry.groups[environment.slug];

                                            return (
                                                <td
                                                    key={environment.slug}
                                                    className="p-3"
                                                >
                                                    {group == null ? (
                                                        <span
                                                            className="text-muted-foreground"
                                                            title="Not in this environment"
                                                            data-test="drift-missing-cell"
                                                        >
                                                            &mdash;
                                                        </span>
                                                    ) : (
                                                        <GroupChip
                                                            group={group}
                                                            muted={
                                                                !entry.differs
                                                            }
                                                        />
                                                    )}
                                                </td>
                                            );
                                        })}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                <p className="text-sm text-muted-foreground">
                    Cells with the same letter hold the same value. A dash means
                    the environment does not expose the key at all.
                </p>
            </div>
        </>
    );
}

ProjectDrift.layout = (props: {
    currentTeam?: { slug: string } | null;
    project: Pick<ProjectSummary, 'name' | 'slug'>;
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
        {
            title: 'Drift',
            href: props.currentTeam
                ? drift([props.currentTeam.slug, props.project.slug])
                : '/',
        },
    ],
});
