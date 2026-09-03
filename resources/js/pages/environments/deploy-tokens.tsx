import { Form, Head, router, usePage } from '@inertiajs/react';
import { Download, KeyRound, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import Code from '@/components/code';
import CopyButton from '@/components/copy-button';
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
import environments, { show as environmentShow } from '@/routes/environments';
import { index as projectsIndex, show as projectShow } from '@/routes/projects';

type DeployTokenRow = {
    id: number;
    name: string;
    clientId: string;
    scopes: string[];
    useCount: number;
    lastUsedAt: string | null;
    revokedAt: string | null;
    createdAt: string | null;
};

type NewToken = { name: string; clientId: string; clientSecret: string };

type Props = {
    project: { name: string; slug: string };
    environment: { name: string; slug: string };
    server: string;
    tokens: DeployTokenRow[];
    newToken: NewToken | null;
};

/**
 * Build the file a deploy server needs, in a shape a shell can source.
 */
function envFileFor(
    token: NewToken,
    server: string,
    project: string,
    environment: string,
): string {
    return [
        `# Envserver deploy token "${token.name}"`,
        `# Grants read only access to ${project}/${environment}, and nothing else.`,
        '# Keep this on the deploy server. Never commit it.',
        '#',
        '# Load it into the environment, then pull:',
        '#',
        '#   set -a; . ./this-file; set +a',
        `#   ${PULL_COMMAND}`,
        '',
        `ENVCLIENT_SERVER=${server}`,
        `ENVCLIENT_CLIENT_ID=${token.clientId}`,
        `ENVCLIENT_CLIENT_SECRET=${token.clientSecret}`,
        '',
    ].join('\n');
}

/**
 * The pull invocation a deploy server needs, not the one a developer's
 * machine does: no terminal is attached there to answer the confirmation
 * prompt, so `--force` is required or the command exits with an error
 * pointing back at this flag. `--constructive` creates the file on the
 * first deploy instead of requiring one to already exist.
 */
const PULL_COMMAND = 'envclient pull --constructive --force --out .env';

/**
 * The exact commands to run on the deploy server: export the token, then
 * pull. Kept separate from {@link envFileFor} so it can be copied on its
 * own, with no secret sitting in shell history if pasted straight into a
 * terminal instead of a file.
 */
function deployScriptFor(
    token: NewToken,
    server: string,
): string {
    return [
        `export ENVCLIENT_SERVER=${server}`,
        `export ENVCLIENT_CLIENT_ID=${token.clientId}`,
        `export ENVCLIENT_CLIENT_SECRET=${token.clientSecret}`,
        '',
        PULL_COMMAND,
        '',
    ].join('\n');
}

/**
 * Hand the token to the browser as a file.
 *
 * Built here in the page rather than fetched from an endpoint: the secret is
 * stored hashed, so the server could not serve this file even if we asked it
 * to. This render is the only moment the plaintext exists anywhere.
 */
function downloadEnvFile(
    token: NewToken,
    server: string,
    project: string,
    environment: string,
): void {
    const contents = envFileFor(token, server, project, environment);
    const url = URL.createObjectURL(
        new Blob([contents], { type: 'text/plain;charset=utf-8' }),
    );

    const link = document.createElement('a');
    link.href = url;
    // .txt rather than .env: neither Windows nor macOS has a handler for a
    // bare .env, so the download would land as a file nothing opens. The
    // contents are still env syntax, ready to be sourced or pasted.
    link.download = `envclient-${project}-${environment}.txt`;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);

    URL.revokeObjectURL(url);
}

/**
 * Describe how hard a token has been leaned on. Uses from before the counter
 * existed are not in the count, so a token can have a moment without a number.
 */
function formatUsage(count: number, lastUsedAt: string | null): string {
    if (!lastUsedAt) {
        return 'Never used';
    }

    const moment = new Date(lastUsedAt).toLocaleString();

    if (count === 0) {
        return `Last used ${moment}`;
    }

    return `Used ${count} ${count === 1 ? 'time' : 'times'} · last ${moment}`;
}

export default function DeployTokens({
    project,
    environment,
    server,
    tokens,
    newToken,
}: Props) {
    const page = usePage();
    const teamSlug = page.props.currentTeam?.slug ?? '';
    const args: [string, string, string] = [
        teamSlug,
        project.slug,
        environment.slug,
    ];

    const [creating, setCreating] = useState(false);

    return (
        <>
            <Head title={`${environment.name} deploy tokens`} />

            <div className="flex flex-col space-y-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <Heading
                        variant="small"
                        title="Deploy tokens"
                        description={`Read only access to ${project.slug}/${environment.slug}, and nothing else.`}
                    />

                    <Dialog open={creating} onOpenChange={setCreating}>
                        <DialogTrigger asChild>
                            <Button data-test="create-deploy-token">
                                <Plus /> New token
                            </Button>
                        </DialogTrigger>
                        <DialogContent>
                            <Form
                                key={String(creating)}
                                {...environments.deployTokens.store.form(args)}
                                className="space-y-6"
                                onSuccess={() => setCreating(false)}
                            >
                                {({ errors, processing }) => (
                                    <>
                                        <DialogHeader>
                                            <DialogTitle>
                                                New deploy token
                                            </DialogTitle>
                                            <DialogDescription>
                                                The secret is shown once. If you
                                                lose it, issue a new token.
                                            </DialogDescription>
                                        </DialogHeader>

                                        <div className="grid gap-2">
                                            <Label htmlFor="name">Name</Label>
                                            <Input
                                                id="name"
                                                name="name"
                                                placeholder="Ploi production"
                                                required
                                                autoFocus
                                            />
                                            <InputError message={errors.name} />
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
                                            >
                                                Create
                                            </Button>
                                        </DialogFooter>
                                    </>
                                )}
                            </Form>
                        </DialogContent>
                    </Dialog>
                </div>

                {newToken ? (
                    <div
                        className="space-y-3 rounded-lg border border-emerald-500/40 bg-emerald-500/5 p-4"
                        data-test="new-token"
                    >
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p className="font-medium">
                                    {newToken.name} created
                                </p>
                                <p className="text-sm text-muted-foreground">
                                    Take it now. The secret is stored hashed, so
                                    this page is the only place it will ever
                                    exist.
                                </p>
                            </div>

                            <div className="flex items-center gap-2">
                                <CopyButton
                                    value={envFileFor(
                                        newToken,
                                        server,
                                        project.slug,
                                        environment.slug,
                                    )}
                                    label="Copy"
                                />
                                <Button
                                    type="button"
                                    onClick={() =>
                                        downloadEnvFile(
                                            newToken,
                                            server,
                                            project.slug,
                                            environment.slug,
                                        )
                                    }
                                    data-test="download-deploy-token"
                                >
                                    <Download /> Download
                                </Button>
                            </div>
                        </div>

                        <pre className="overflow-x-auto rounded-md bg-muted p-3 font-mono text-xs">
                            {envFileFor(
                                newToken,
                                server,
                                project.slug,
                                environment.slug,
                            )}
                        </pre>

                        <div className="space-y-2">
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <p className="text-sm font-medium">
                                    Or run it directly on the deploy server
                                </p>
                                <CopyButton
                                    value={deployScriptFor(newToken, server)}
                                    label="Copy"
                                    variant="ghost"
                                />
                            </div>

                            <pre className="overflow-x-auto rounded-md bg-muted p-3 font-mono text-xs">
                                {deployScriptFor(newToken, server)}
                            </pre>

                            <ul className="space-y-1 text-sm text-muted-foreground">
                                <li>
                                    <Code>--constructive</Code> also adds keys
                                    your .env does not have yet, so the first
                                    deploy can create it from nothing.
                                </li>
                                <li>
                                    <Code>--force</Code> skips the
                                    confirmation prompt. A deploy server has
                                    no terminal to answer it, so pull refuses
                                    to run without this flag.
                                </li>
                                <li>
                                    <Code>--out .env</Code> sets where the
                                    file is written; drop it to use{' '}
                                    <Code>.env</Code> next to{' '}
                                    <Code>envclient.json</Code>.
                                </li>
                            </ul>
                        </div>
                    </div>
                ) : null}

                {tokens.length === 0 ? (
                    <div className="flex flex-col items-center gap-2 rounded-lg border border-dashed p-12 text-center">
                        <KeyRound className="size-8 text-muted-foreground" />
                        <p className="font-medium">No deploy tokens yet</p>
                        <p className="text-sm text-muted-foreground">
                            Create one to let a server fetch this environment
                            during a deploy.
                        </p>
                    </div>
                ) : (
                    <div className="space-y-3">
                        {tokens.map((token) => (
                            <div
                                key={token.id}
                                data-test="deploy-token-row"
                                className="flex flex-wrap items-center justify-between gap-3 rounded-lg border p-4"
                            >
                                <div>
                                    <div className="flex items-center gap-2">
                                        <span className="font-medium">
                                            {token.name}
                                        </span>
                                        {token.revokedAt ? (
                                            <Badge variant="outline">
                                                Revoked
                                            </Badge>
                                        ) : null}
                                    </div>
                                    <p className="mt-1">
                                        <Code className="text-xs text-muted-foreground">
                                            {token.clientId}
                                        </Code>
                                    </p>
                                    <p className="text-sm text-muted-foreground">
                                        {formatUsage(
                                            token.useCount,
                                            token.lastUsedAt,
                                        )}
                                    </p>
                                </div>

                                {token.revokedAt ? null : (
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        aria-label="Revoke token"
                                        data-test="revoke-deploy-token"
                                        onClick={() =>
                                            router.delete(
                                                environments.deployTokens.destroy.url(
                                                    [...args, token.id],
                                                ),
                                            )
                                        }
                                    >
                                        <Trash2 className="size-4" />
                                    </Button>
                                )}
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}

DeployTokens.layout = (
    props: Props & { currentTeam?: { slug: string } | null },
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
                title: 'Deploy tokens',
                href: environments.deployTokens.index([
                    team,
                    props.project.slug,
                    props.environment.slug,
                ]),
            },
        ],
    };
};
