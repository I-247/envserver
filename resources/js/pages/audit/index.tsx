import { Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import { audit } from '@/routes';

type AuditEventRow = {
    id: number;
    action: string;
    label: string;
    actor: string | null;
    metadata: Record<string, unknown> | null;
    ipAddress: string | null;
    createdAt: string | null;
};

type Props = {
    events: AuditEventRow[];
};

function describe(metadata: Record<string, unknown> | null): string {
    if (!metadata) {
        return '';
    }

    return Object.entries(metadata)
        .map(([key, value]) => `${key}=${String(value)}`)
        .join('  ');
}

export default function AuditIndex({ events }: Props) {
    return (
        <>
            <Head title="Audit trail" />

            <div className="flex flex-col space-y-6 p-4">
                <Heading
                    variant="small"
                    title="Audit trail"
                    description="Who did what, including who looked at a secret. Values are never recorded here."
                />

                {events.length === 0 ? (
                    <div className="rounded-lg border border-dashed p-12 text-center">
                        <p className="font-medium">Nothing recorded yet</p>
                    </div>
                ) : (
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-left text-sm">
                            <thead className="border-b bg-muted/40">
                                <tr>
                                    <th className="p-3 font-medium">When</th>
                                    <th className="p-3 font-medium">Who</th>
                                    <th className="p-3 font-medium">What</th>
                                    <th className="p-3 font-medium">Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                {events.map((event) => (
                                    <tr
                                        key={event.id}
                                        data-test="audit-row"
                                        className="border-b last:border-0"
                                    >
                                        <td className="p-3 whitespace-nowrap text-muted-foreground">
                                            {event.createdAt
                                                ? new Date(
                                                      event.createdAt,
                                                  ).toLocaleString()
                                                : ''}
                                        </td>
                                        <td className="p-3">
                                            {event.actor ?? 'System'}
                                        </td>
                                        <td className="p-3">{event.label}</td>
                                        <td className="p-3 font-mono text-xs text-muted-foreground">
                                            {describe(event.metadata)}
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

AuditIndex.layout = (props: { currentTeam?: { slug: string } | null }) => ({
    breadcrumbs: [
        {
            title: 'Audit trail',
            href: props.currentTeam ? audit(props.currentTeam.slug) : '/',
        },
    ],
});
