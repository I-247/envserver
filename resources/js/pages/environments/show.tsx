import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import { History, KeyRound, Plus, Share2, Trash2, Upload } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import VariableValue from '@/components/variable-value';
import environments, { show as environmentShow } from '@/routes/environments';
import { index as projectsIndex, show as projectShow } from '@/routes/projects';
import type {
    EnvironmentPermissions,
    EnvironmentVariable,
    PendingChange,
} from '@/types';

type Props = {
    project: { name: string; slug: string };
    environment: { name: string; slug: string; autoPublish: boolean };
    variables: EnvironmentVariable[];
    pending: PendingChange[];
    latestRelease: { version: number; message: string | null } | null;
    permissions: EnvironmentPermissions;
};

export default function EnvironmentShow({
    project,
    environment,
    variables,
    pending,
    latestRelease,
    permissions,
}: Props) {
    const page = usePage();
    const teamSlug = page.props.currentTeam?.slug ?? '';
    const args: [string, string, string] = [
        teamSlug,
        project.slug,
        environment.slug,
    ];

    const [adding, setAdding] = useState(false);

    return (
        <>
            <Head title={`${project.name} · ${environment.name}`} />

            <div className="flex flex-col space-y-6 p-4">
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

                    <div className="flex items-center gap-2">
                        <Button variant="secondary" asChild>
                            <Link href={environments.releases.index(args)}>
                                <History /> History
                            </Link>
                        </Button>

                        {permissions.canManageDeployToken ? (
                            <Button variant="secondary" asChild>
                                <Link
                                    href={environments.deployTokens.index(args)}
                                >
                                    <KeyRound /> Deploy tokens
                                </Link>
                            </Button>
                        ) : null}

                        {permissions.canManageVariable ? (
                            <Dialog open={adding} onOpenChange={setAdding}>
                                <DialogTrigger asChild>
                                    <Button data-test="add-variable">
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
                                                        It becomes part of this
                                                        environment right away,
                                                        and of the next release.
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
                                                        message={errors.value}
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
                                                        disabled={processing}
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

                        <ul className="space-y-1 font-mono text-sm">
                            {pending.map((change) => (
                                <li
                                    key={change.key}
                                    className="flex items-center gap-2"
                                >
                                    <span className="w-16 text-muted-foreground">
                                        {change.type}
                                    </span>
                                    <span>{change.key}</span>
                                </li>
                            ))}
                        </ul>
                    </div>
                ) : null}

                {variables.length === 0 ? (
                    <div className="rounded-lg border border-dashed p-12 text-center">
                        <p className="font-medium">No variables yet</p>
                        <p className="text-sm text-muted-foreground">
                            Add one here, or push an existing .env with{' '}
                            <code className="font-mono">kluis push</code>.
                        </p>
                    </div>
                ) : (
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-left text-sm">
                            <thead className="border-b bg-muted/40">
                                <tr>
                                    <th className="p-3 font-medium">Name</th>
                                    <th className="p-3 font-medium">Value</th>
                                    <th className="p-3 font-medium">Version</th>
                                    <th className="p-3" />
                                </tr>
                            </thead>
                            <tbody>
                                {variables.map((variable) => (
                                    <tr
                                        key={variable.id}
                                        className="border-b last:border-0"
                                        data-test="variable-row"
                                    >
                                        <td className="p-3">
                                            <div className="flex items-center gap-2">
                                                <span className="font-mono">
                                                    {variable.key}
                                                </span>
                                                {variable.shared ? (
                                                    <Badge variant="secondary">
                                                        <Share2 className="size-3" />
                                                        {variable.sharedWith}
                                                    </Badge>
                                                ) : null}
                                            </div>
                                            {variable.alias ? (
                                                <span className="text-xs text-muted-foreground">
                                                    aliased from{' '}
                                                    {variable.ownKey}
                                                </span>
                                            ) : null}
                                        </td>
                                        <td className="p-3">
                                            <VariableValue
                                                canReveal={
                                                    permissions.canViewSecretValue
                                                }
                                                revealUrl={environments.variables.reveal.url(
                                                    [...args, variable.id],
                                                )}
                                            />
                                        </td>
                                        <td className="p-3 text-muted-foreground">
                                            v{variable.version}
                                        </td>
                                        <td className="p-3 text-right">
                                            {permissions.canManageVariable ? (
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    aria-label="Remove from this environment"
                                                    data-test="detach-variable"
                                                    onClick={() =>
                                                        router.delete(
                                                            environments.variables.destroy.url(
                                                                [
                                                                    ...args,
                                                                    variable.id,
                                                                ],
                                                            ),
                                                        )
                                                    }
                                                >
                                                    <Trash2 className="size-4" />
                                                </Button>
                                            ) : null}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
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
