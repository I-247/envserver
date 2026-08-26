import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import {
    ChevronDown,
    Clock,
    FileUp,
    History,
    KeyRound,
    Link2,
    Pencil,
    Plus,
    Search,
    Share2,
    Trash2,
    Upload,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import Code from '@/components/code';
import DeleteEnvironmentModal from '@/components/delete-environment-modal';
import DeleteVariableModal from '@/components/delete-variable-modal';
import DownloadEnvModal from '@/components/download-env-modal';
import EditEnvironmentModal from '@/components/edit-environment-modal';
import EditVariableModal from '@/components/edit-variable-modal';
import Heading from '@/components/heading';
import ImportEnvModal from '@/components/import-env-modal';
import InputError from '@/components/input-error';
import ShareVariableModal from '@/components/share-variable-modal';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import VariableValue from '@/components/variable-value';
import environments, { show as environmentShow } from '@/routes/environments';
import { index as projectsIndex, show as projectShow } from '@/routes/projects';
import type {
    EnvironmentPermissions,
    EnvironmentSummary,
    EnvironmentVariable,
    PendingChange,
    ShareableVariable,
} from '@/types';

type Props = {
    project: { name: string; slug: string };
    environment: EnvironmentSummary;
    variables: EnvironmentVariable[];
    pending: PendingChange[];
    latestRelease: { version: number; message: string | null } | null;
    permissions: EnvironmentPermissions;
    shareable?: ShareableVariable[];
};

export default function EnvironmentShow({
    project,
    environment,
    variables,
    pending,
    latestRelease,
    permissions,
    shareable,
}: Props) {
    const page = usePage();
    const teamSlug = page.props.currentTeam?.slug ?? '';
    const args: [string, string, string] = [
        teamSlug,
        project.slug,
        environment.slug,
    ];

    const [adding, setAdding] = useState(false);
    const [importing, setImporting] = useState(false);
    const [search, setSearch] = useState('');
    const [detaching, setDetaching] = useState<EnvironmentVariable | null>(
        null,
    );

    /**
     * Filtering happens here rather than server-side: the page already ships
     * every variable of the environment, so a round trip would only add
     * latency.
     */
    const filteredVariables = useMemo(() => {
        const needle = search.trim().toLowerCase();

        if (needle === '') {
            return variables;
        }

        return variables.filter((variable) =>
            [variable.key, variable.ownKey ?? '']
                .join(' ')
                .toLowerCase()
                .includes(needle),
        );
    }, [variables, search]);

    /**
     * Offer this project's variable to the team, or withdraw the offer.
     *
     * Withdrawing does not reclaim it from projects already using it, so the
     * confirm only asks about future use.
     */
    const toggleOffer = (variable: EnvironmentVariable) => {
        router.patch(
            environments.variables.shareable.url([...args, variable.id]),
            { shareable: !variable.shareable },
            { preserveScroll: true },
        );
    };

    return (
        <>
            <Head title={`${project.name} · ${environment.name}`} />

            <div className="flex flex-col space-y-6 p-4">
                <div className="space-y-4">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <Heading
                            variant="small"
                            title={environment.name}
                            description={
                                latestRelease
                                    ? `Release ${latestRelease.version} is live`
                                    : 'Nothing published yet'
                            }
                        />

                        <div className="flex items-center gap-1">
                            {permissions.canManageEnvironment ? (
                                <EditEnvironmentModal
                                    teamSlug={teamSlug}
                                    projectSlug={project.slug}
                                    environment={environment}
                                >
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        data-test="environment-edit-button"
                                        aria-label="Edit environment"
                                        title="Edit environment"
                                    >
                                        <Pencil />
                                    </Button>
                                </EditEnvironmentModal>
                            ) : null}

                            {permissions.canDeleteEnvironment ? (
                                <DeleteEnvironmentModal
                                    teamSlug={teamSlug}
                                    projectSlug={project.slug}
                                    environment={environment}
                                >
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        data-test="environment-delete-button"
                                        aria-label="Delete environment"
                                        title="Delete environment"
                                        className="text-muted-foreground hover:text-destructive"
                                    >
                                        <Trash2 />
                                    </Button>
                                </DeleteEnvironmentModal>
                            ) : null}
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <div className="flex flex-wrap items-center gap-2">
                            <Button variant="secondary" asChild>
                                <Link href={environments.releases.index(args)}>
                                    <History /> History
                                </Link>
                            </Button>

                            {permissions.canManageDeployToken ? (
                                <Button variant="secondary" asChild>
                                    <Link
                                        href={environments.deployTokens.index(
                                            args,
                                        )}
                                    >
                                        <KeyRound /> Deploy tokens
                                    </Link>
                                </Button>
                            ) : null}

                            {permissions.canManageVariable ? (
                                <ShareVariableModal
                                    args={args}
                                    shareable={shareable}
                                />
                            ) : null}

                            {permissions.canViewSecretValue &&
                            variables.length > 0 ? (
                                <DownloadEnvModal
                                    args={args}
                                    environmentName={environment.name}
                                    variableCount={variables.length}
                                />
                            ) : null}
                        </div>

                        {permissions.canManageVariable ? (
                            <div className="flex items-center">
                                <Dialog open={adding} onOpenChange={setAdding}>
                                    <DialogTrigger asChild>
                                        <Button
                                            data-test="add-variable"
                                            className="rounded-r-none"
                                        >
                                            <Plus /> Add variable
                                        </Button>
                                    </DialogTrigger>
                                    <DialogContent>
                                        <Form
                                            key={String(adding)}
                                            {...environments.variables.store.form(
                                                args,
                                            )}
                                            className="space-y-6"
                                            onSuccess={() => setAdding(false)}
                                        >
                                            {({ errors, processing }) => (
                                                <>
                                                    <DialogHeader>
                                                        <DialogTitle>
                                                            Add a variable
                                                        </DialogTitle>
                                                        <DialogDescription>
                                                            It becomes part of
                                                            this environment
                                                            right away, and of
                                                            the next release.
                                                        </DialogDescription>
                                                    </DialogHeader>

                                                    <div className="grid gap-2">
                                                        <Label htmlFor="key">
                                                            Name
                                                        </Label>
                                                        <Input
                                                            id="key"
                                                            name="key"
                                                            placeholder="DB_PASSWORD"
                                                            className="font-mono"
                                                            required
                                                            autoFocus
                                                        />
                                                        <InputError
                                                            message={errors.key}
                                                        />
                                                    </div>

                                                    <div className="grid gap-2">
                                                        <Label htmlFor="value">
                                                            Value
                                                        </Label>
                                                        <Input
                                                            id="value"
                                                            name="value"
                                                            className="font-mono"
                                                            autoComplete="off"
                                                        />
                                                        <InputError
                                                            message={
                                                                errors.value
                                                            }
                                                        />
                                                    </div>

                                                    <DialogFooter className="gap-2">
                                                        <DialogClose asChild>
                                                            <Button variant="secondary">
                                                                Cancel
                                                            </Button>
                                                        </DialogClose>
                                                        <Button
                                                            type="submit"
                                                            disabled={
                                                                processing
                                                            }
                                                            data-test="add-variable-submit"
                                                        >
                                                            Add
                                                        </Button>
                                                    </DialogFooter>
                                                </>
                                            )}
                                        </Form>
                                    </DialogContent>
                                </Dialog>

                                <DropdownMenu>
                                    <DropdownMenuTrigger asChild>
                                        <Button
                                            data-test="variable-actions"
                                            aria-label="More variable actions"
                                            className="rounded-l-none border-l border-primary-foreground/20 px-2"
                                        >
                                            <ChevronDown />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end">
                                        <DropdownMenuItem
                                            data-test="import-env"
                                            onSelect={() => setImporting(true)}
                                        >
                                            <FileUp /> Import .env file
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>

                                <ImportEnvModal
                                    args={args}
                                    open={importing}
                                    onOpenChange={setImporting}
                                />
                            </div>
                        ) : null}
                    </div>
                </div>

                {pending.length > 0 ? (
                    <div
                        className="space-y-3 rounded-lg border border-amber-500/40 bg-amber-500/5 p-4"
                        data-test="pending-changes"
                    >
                        <div className="flex items-center justify-between gap-3">
                            <div>
                                <p className="font-medium">
                                    {pending.length} change
                                    {pending.length === 1 ? '' : 's'} waiting
                                </p>
                                <p className="text-sm text-muted-foreground">
                                    {environment.autoPublish
                                        ? 'Publish to pin these into a release.'
                                        : 'This environment publishes manually, so nothing is live until you say so.'}
                                </p>
                            </div>

                            {permissions.canPublishRelease ? (
                                <Form
                                    {...environments.releases.store.form(args)}
                                >
                                    {({ processing }) => (
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                            data-test="publish-release"
                                        >
                                            <Upload /> Publish
                                        </Button>
                                    )}
                                </Form>
                            ) : null}
                        </div>

                        <ul className="space-y-1 text-sm">
                            {pending.map((change) => (
                                <li
                                    key={change.key}
                                    className="flex items-center gap-2"
                                >
                                    <span className="w-16 text-muted-foreground">
                                        {change.type}
                                    </span>
                                    <Code>{change.key}</Code>
                                </li>
                            ))}
                        </ul>
                    </div>
                ) : null}

                {variables.length === 0 ? (
                    <div className="rounded-lg border border-dashed p-12 text-center">
                        <p className="font-medium">No variables yet</p>
                        <p className="text-sm text-muted-foreground">
                            Add one here, paste a whole <Code>.env</Code>, or
                            push one with <Code>kluis push</Code>.
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
                                placeholder="Search variables..."
                                aria-label="Search variables"
                                data-test="variables-search"
                                className="pl-9"
                            />
                        </div>

                        {filteredVariables.length === 0 ? (
                            <div
                                className="rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground"
                                data-test="variables-empty-search"
                            >
                                No variables match &ldquo;{search}&rdquo;.
                            </div>
                        ) : (
                            <div className="overflow-x-auto rounded-lg border">
                                <table className="w-full text-left text-sm">
                                    <thead className="border-b bg-muted/40">
                                        <tr>
                                            <th className="p-3 font-medium">
                                                Name
                                            </th>
                                            <th className="p-3 font-medium">
                                                Value
                                            </th>
                                            <th className="p-3 font-medium">
                                                Version
                                            </th>
                                            <th className="p-3" />
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {filteredVariables.map((variable) => (
                                            <tr
                                                key={variable.id}
                                                className="border-b last:border-0"
                                                data-test="variable-row"
                                            >
                                                <td className="p-3">
                                                    <div className="flex items-center gap-2">
                                                        <Code>
                                                            {variable.key}
                                                        </Code>
                                                        {variable.shared ? (
                                                            <Badge variant="secondary">
                                                                <Share2 className="size-3" />
                                                                {
                                                                    variable.sharedWith
                                                                }
                                                            </Badge>
                                                        ) : null}
                                                        {variable.borrowed ? (
                                                            <Badge
                                                                variant="outline"
                                                                data-test="borrowed-badge"
                                                            >
                                                                <Link2 className="size-3" />
                                                                Borrowed
                                                            </Badge>
                                                        ) : null}
                                                        {variable.canOffer &&
                                                        variable.shareable ? (
                                                            <Badge
                                                                variant="outline"
                                                                data-test="offered-badge"
                                                            >
                                                                Shared
                                                            </Badge>
                                                        ) : null}
                                                        {variable.rotation
                                                            .overdueByDays >
                                                        0 ? (
                                                            <Badge
                                                                variant="outline"
                                                                className="text-destructive"
                                                                data-test="overdue-badge"
                                                                title={`Past the ${variable.rotation.intervalDays} day rotation interval`}
                                                            >
                                                                <Clock className="size-3" />
                                                                {
                                                                    variable
                                                                        .rotation
                                                                        .overdueByDays
                                                                }
                                                                d overdue
                                                            </Badge>
                                                        ) : null}
                                                    </div>
                                                    <div className="flex flex-wrap items-center gap-x-2 text-xs text-muted-foreground">
                                                        {variable.alias ? (
                                                            <span>
                                                                aliased from{' '}
                                                                {
                                                                    variable.ownKey
                                                                }
                                                            </span>
                                                        ) : null}
                                                        {variable.borrowed &&
                                                        variable.owner ? (
                                                            <Link
                                                                href={projectShow(
                                                                    [
                                                                        teamSlug,
                                                                        variable
                                                                            .owner
                                                                            .slug,
                                                                    ],
                                                                )}
                                                                className="underline underline-offset-2 hover:text-foreground"
                                                                data-test="variable-owner"
                                                            >
                                                                owned by{' '}
                                                                {
                                                                    variable
                                                                        .owner
                                                                        .name
                                                                }
                                                            </Link>
                                                        ) : null}
                                                    </div>
                                                </td>
                                                <td className="p-3">
                                                    <VariableValue
                                                        variableKey={
                                                            variable.key
                                                        }
                                                        canReveal={
                                                            permissions.canViewSecretValue
                                                        }
                                                        revealUrl={environments.variables.reveal.url(
                                                            [
                                                                ...args,
                                                                variable.id,
                                                            ],
                                                        )}
                                                    />
                                                </td>
                                                <td className="p-3 text-muted-foreground">
                                                    v{variable.version}
                                                </td>
                                                <td className="p-3">
                                                    <div className="flex items-center justify-end gap-1">
                                                        {permissions.canManageVariable &&
                                                        variable.canOffer ? (
                                                            <Button
                                                                variant="ghost"
                                                                size="icon"
                                                                data-test="toggle-shareable"
                                                                aria-pressed={
                                                                    variable.shareable
                                                                }
                                                                title={
                                                                    variable.shareable
                                                                        ? 'Stop offering this variable to other projects'
                                                                        : 'Let other projects use this variable'
                                                                }
                                                                aria-label={
                                                                    variable.shareable
                                                                        ? 'Stop offering this variable to other projects'
                                                                        : 'Let other projects use this variable'
                                                                }
                                                                onClick={() =>
                                                                    toggleOffer(
                                                                        variable,
                                                                    )
                                                                }
                                                            >
                                                                <Share2
                                                                    className={`size-4 ${
                                                                        variable.shareable
                                                                            ? 'text-foreground'
                                                                            : 'text-muted-foreground/50'
                                                                    }`}
                                                                />
                                                            </Button>
                                                        ) : null}

                                                        {permissions.canManageVariable ? (
                                                            <EditVariableModal
                                                                args={args}
                                                                variable={
                                                                    variable
                                                                }
                                                            >
                                                                <Button
                                                                    variant="ghost"
                                                                    size="icon"
                                                                    aria-label={`Edit ${variable.key}`}
                                                                    data-test="edit-variable"
                                                                >
                                                                    <Pencil className="size-4" />
                                                                </Button>
                                                            </EditVariableModal>
                                                        ) : null}

                                                        {permissions.canManageVariable ? (
                                                            <Button
                                                                variant="ghost"
                                                                size="icon"
                                                                aria-label="Remove from this environment"
                                                                data-test="detach-variable"
                                                                onClick={() =>
                                                                    setDetaching(
                                                                        variable,
                                                                    )
                                                                }
                                                            >
                                                                <Trash2 className="size-4" />
                                                            </Button>
                                                        ) : null}
                                                    </div>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </div>
                )}
            </div>

            <DeleteVariableModal
                args={args}
                environmentName={environment.name}
                variable={detaching}
                onOpenChange={(open) => {
                    if (!open) {
                        setDetaching(null);
                    }
                }}
            />
        </>
    );
}

EnvironmentShow.layout = (
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
        ],
    };
};
