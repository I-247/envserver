import { Form, Head, router, usePage } from '@inertiajs/react';
import { KeyRound, Plus, Trash2 } from 'lucide-react';
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
import environments, { show as environmentShow } from '@/routes/environments';
import { index as projectsIndex, show as projectShow } from '@/routes/projects';

type DeployTokenRow = {
    id: number;
    name: string;
    clientId: string;
    scopes: string[];
    lastUsedAt: string | null;
    revokedAt: string | null;
    createdAt: string | null;
};

type Props = {
    project: { name: string; slug: string };
    environment: { name: string; slug: string };
    server: string;
    tokens: DeployTokenRow[];
    newToken: { name: string; clientId: string; clientSecret: string } | null;
};

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
                        <p className="font-medium">
                            {newToken.name} created. Copy this now, it is not
                            shown again.
                        </p>
                        <pre className="overflow-x-auto rounded-md bg-muted p-3 font-mono text-xs">
                            {`export KLUIS_SERVER=${server}
export KLUIS_CLIENT_ID=${newToken.clientId}
export KLUIS_CLIENT_SECRET=${newToken.clientSecret}

kluis pull --constructive --out .env`}
                        </pre>
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
                                    <p className="font-mono text-xs text-muted-foreground">
                                        {token.clientId}
                                    </p>
                                    <p className="text-sm text-muted-foreground">
                                        {token.lastUsedAt
                                            ? `Last used ${new Date(token.lastUsedAt).toLocaleString()}`
                                            : 'Never used'}
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
