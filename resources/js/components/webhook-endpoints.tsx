import { Form } from '@inertiajs/react';
import { Plus, Trash2, TriangleAlert } from 'lucide-react';
import AddWebhookModal from '@/components/add-webhook-modal';
import Code from '@/components/code';
import CopyButton from '@/components/copy-button';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { destroy } from '@/routes/teams/webhooks';
import type { Team, WebhookEndpointSummary, WebhookOption } from '@/types';

type Props = {
    team: Team;
    endpoints: WebhookEndpointSummary[];
    kinds: WebhookOption[];
    events: WebhookOption[];
    /** Shown once, right after an endpoint is added. It is never readable again. */
    newSecret?: string | null;
};

function describeDelivery(endpoint: WebhookEndpointSummary): string {
    if (endpoint.lastAttemptedAt === null) {
        return 'Nothing delivered yet';
    }

    const when = new Date(endpoint.lastAttemptedAt).toLocaleString(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    });

    return endpoint.lastError
        ? `Failed ${when} — ${endpoint.lastError}`
        : `Delivered ${when} (${endpoint.lastStatus})`;
}

export default function WebhookEndpoints({
    team,
    endpoints,
    kinds,
    events,
    newSecret,
}: Props) {
    return (
        <div className="space-y-6">
            <div className="flex items-start justify-between gap-4">
                <Heading
                    variant="small"
                    title="Webhooks"
                    description="Where this team's audit events are sent. A trail nobody reads only tells you what happened once you have gone looking."
                />

                <AddWebhookModal team={team} kinds={kinds} events={events}>
                    <Button data-test="webhook-add-button">
                        <Plus /> Add endpoint
                    </Button>
                </AddWebhookModal>
            </div>

            {newSecret ? (
                <div
                    className="space-y-2 rounded-lg border border-dashed p-4"
                    data-test="webhook-new-secret"
                >
                    <p className="text-sm font-medium">
                        Signing secret — shown once
                    </p>
                    <div className="flex items-center gap-2">
                        <Code className="truncate">{newSecret}</Code>
                        <CopyButton
                            value={newSecret}
                            label="Copy signing secret"
                            variant="outline"
                            size="sm"
                        />
                    </div>
                    <p className="text-sm text-muted-foreground">
                        Deliveries carry{' '}
                        <Code>X-Envserver-Signature: sha256=…</Code>, an HMAC of the
                        exact body with this secret. Envserver cannot show it again;
                        an endpoint that loses it needs a new one.
                    </p>
                </div>
            ) : null}

            {endpoints.length === 0 ? (
                <div className="rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground">
                    No endpoints yet. Everything is still recorded in the audit
                    trail either way.
                </div>
            ) : (
                <div className="divide-y rounded-lg border">
                    {endpoints.map((endpoint) => (
                        <div
                            key={endpoint.id}
                            data-test="webhook-row"
                            className="flex items-start justify-between gap-4 p-4"
                        >
                            <div className="min-w-0 space-y-1">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="font-medium">
                                        {endpoint.name}
                                    </span>
                                    <Badge variant="secondary">
                                        {endpoint.kindLabel}
                                    </Badge>
                                    {endpoint.active ? null : (
                                        <Badge
                                            variant="outline"
                                            className="text-destructive"
                                            data-test="webhook-retired-badge"
                                        >
                                            <TriangleAlert className="size-3" />
                                            Switched off after{' '}
                                            {endpoint.consecutiveFailures}{' '}
                                            failures
                                        </Badge>
                                    )}
                                </div>
                                <p className="truncate text-xs text-muted-foreground">
                                    {endpoint.url}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    {endpoint.events.length === 0
                                        ? 'Every event'
                                        : `${endpoint.events.length} event${endpoint.events.length === 1 ? '' : 's'}`}{' '}
                                    · {describeDelivery(endpoint)}
                                </p>
                            </div>

                            <Form
                                {...destroy.form.delete([
                                    team.slug,
                                    endpoint.id,
                                ])}
                                options={{ preserveScroll: true }}
                            >
                                {({ processing }) => (
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        size="sm"
                                        disabled={processing}
                                        data-test="webhook-delete"
                                        className="text-destructive hover:text-destructive"
                                    >
                                        <Trash2 />
                                        Remove
                                    </Button>
                                )}
                            </Form>
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}
