import { Head, Link, usePage } from '@inertiajs/react';
import {
    Boxes,
    Clock,
    KeyRound,
    Layers,
    Lock,
    RefreshCw,
    Rocket,
    ScrollText,
    Upload,
    Variable as VariableIcon,
} from 'lucide-react';
import type { ComponentType, ReactNode } from 'react';
import { useState } from 'react';
import PendingInvitationsModal from '@/components/pending-invitations-modal';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { audit, dashboard } from '@/routes';
import environments, { show as environmentShow } from '@/routes/environments';
import { index as projectsIndex } from '@/routes/projects';
import type {
    DashboardActivity,
    DashboardDeployToken,
    DashboardEncryption,
    DashboardInvitation,
    DashboardRelease,
    DashboardStaleSecrets,
    DashboardStats,
    PendingEnvironment,
} from '@/types';

type Props = {
    pendingInvitations?: DashboardInvitation[];
    stats: DashboardStats;
    pendingEnvironments: PendingEnvironment[];
    recentReleases: DashboardRelease[];
    deployTokens: DashboardDeployToken[];
    staleSecrets: DashboardStaleSecrets;
    recentActivity: DashboardActivity[] | null;
    encryption: DashboardEncryption;
};

function formatMoment(value: string | null): string {
    if (!value) {
        return 'never';
    }

    return new Date(value).toLocaleString(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}

function Stat({
    label,
    value,
    icon: Icon,
}: {
    label: string;
    value: number;
    icon: ComponentType<{ className?: string }>;
}) {
    return (
        <div
            data-test="dashboard-stat"
            className="flex items-center gap-3 rounded-xl border p-4"
        >
            <Icon className="size-5 text-muted-foreground" />
            <div>
                <p className="text-xl leading-none font-semibold">{value}</p>
                <p className="mt-1 text-xs text-muted-foreground">{label}</p>
            </div>
        </div>
    );
}

function Widget({
    title,
    description,
    action,
    children,
}: {
    title: string;
    description: string;
    action?: ReactNode;
    children: ReactNode;
}) {
    return (
        <section className="flex flex-col rounded-xl border">
            <header className="flex items-start justify-between gap-3 border-b p-4">
                <div>
                    <h2 className="text-sm font-medium">{title}</h2>
                    <p className="text-xs text-muted-foreground">
                        {description}
                    </p>
                </div>
                {action}
            </header>
            <div className="flex-1 p-2">{children}</div>
        </section>
    );
}

function EncryptionStep({
    title,
    icon: Icon,
    children,
}: {
    title: string;
    icon: ComponentType<{ className?: string }>;
    children: ReactNode;
}) {
    return (
        <div className="flex gap-3 p-3">
            <Icon className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
            <div>
                <p className="text-sm font-medium">{title}</p>
                <p className="mt-1 text-xs text-muted-foreground">{children}</p>
            </div>
        </div>
    );
}

function Empty({ children }: { children: ReactNode }) {
    return <p className="p-4 text-xs text-muted-foreground">{children}</p>;
}

export default function Dashboard({
    pendingInvitations = [],
    stats,
    pendingEnvironments,
    recentReleases,
    deployTokens,
    staleSecrets,
    recentActivity,
    encryption,
}: Props) {
    const [showInvitations, setShowInvitations] = useState(
        pendingInvitations.length > 0,
    );
    const page = usePage();
    const teamSlug = page.props.currentTeam?.slug ?? '';

    return (
        <>
            <Head title="Dashboard" />
            <PendingInvitationsModal
                invitations={pendingInvitations}
                open={pendingInvitations.length > 0 && showInvitations}
                onOpenChange={setShowInvitations}
            />

            <div className="flex flex-col gap-4 p-4">
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <Stat
                        label="Projects"
                        value={stats.projects}
                        icon={Boxes}
                    />
                    <Stat
                        label="Environments"
                        value={stats.environments}
                        icon={Layers}
                    />
                    <Stat
                        label="Variables"
                        value={stats.variables}
                        icon={VariableIcon}
                    />
                    <Stat
                        label="Active deploy tokens"
                        value={stats.deployTokens}
                        icon={KeyRound}
                    />
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <Widget
                        title="Waiting to publish"
                        description="Manual environments whose variables drifted from their last release"
                        action={
                            <Button
                                variant="secondary"
                                size="sm"
                                className="text-xs"
                                asChild
                            >
                                <Link href={projectsIndex(teamSlug)}>
                                    Projects
                                </Link>
                            </Button>
                        }
                    >
                        {pendingEnvironments.length === 0 ? (
                            <Empty>
                                Every environment matches its last release.
                            </Empty>
                        ) : (
                            pendingEnvironments.map((row) => (
                                <Link
                                    key={`${row.project.slug}/${row.environment.slug}`}
                                    data-test="dashboard-pending-row"
                                    href={environmentShow([
                                        teamSlug,
                                        row.project.slug,
                                        row.environment.slug,
                                    ])}
                                    className="flex items-center justify-between gap-3 rounded-lg p-3 transition-colors hover:bg-accent"
                                >
                                    <div className="min-w-0">
                                        <p className="truncate text-sm font-medium">
                                            {row.project.name} ·{' '}
                                            {row.environment.name}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {row.version === null
                                                ? 'Never published'
                                                : `Live on release ${row.version}`}
                                        </p>
                                    </div>
                                    <Badge variant="secondary">
                                        <Upload />
                                        {row.changes} pending
                                    </Badge>
                                </Link>
                            ))
                        )}
                    </Widget>

                    <Widget
                        title="Recent releases"
                        description="The last snapshots the CLI can pull"
                    >
                        {recentReleases.length === 0 ? (
                            <Empty>Nothing published yet.</Empty>
                        ) : (
                            recentReleases.map((release) => (
                                <Link
                                    key={release.id}
                                    data-test="dashboard-release-row"
                                    href={environments.releases.index([
                                        teamSlug,
                                        release.project.slug,
                                        release.environment.slug,
                                    ])}
                                    className="flex items-center justify-between gap-3 rounded-lg p-3 transition-colors hover:bg-accent"
                                >
                                    <div className="min-w-0">
                                        <p className="truncate text-sm font-medium">
                                            {release.project.name} ·{' '}
                                            {release.environment.name}
                                        </p>
                                        <p className="truncate text-xs text-muted-foreground">
                                            {release.message ??
                                                `${release.variablesCount} variables`}
                                            {release.publishedBy
                                                ? ` — ${release.publishedBy}`
                                                : ''}
                                        </p>
                                    </div>
                                    <Badge variant="secondary">
                                        <Rocket />v{release.version}
                                    </Badge>
                                </Link>
                            ))
                        )}
                    </Widget>

                    <Widget
                        title="Deploy tokens"
                        description="Usable tokens, the ones expiring soonest first"
                    >
                        {deployTokens.length === 0 ? (
                            <Empty>No deploy tokens issued.</Empty>
                        ) : (
                            deployTokens.map((token) => (
                                <div
                                    key={`${token.project}/${token.environment}/${token.name}`}
                                    data-test="dashboard-token-row"
                                    className="flex items-center justify-between gap-3 p-3"
                                >
                                    <div className="min-w-0">
                                        <p className="truncate text-sm font-medium">
                                            {token.name}
                                        </p>
                                        <p className="truncate text-xs text-muted-foreground">
                                            {token.project} ·{' '}
                                            {token.environment}
                                        </p>
                                    </div>
                                    <div className="text-right text-xs text-muted-foreground">
                                        <p>
                                            Used{' '}
                                            {formatMoment(token.lastUsedAt)}
                                        </p>
                                        <p>
                                            {token.expiresAt
                                                ? `Expires ${formatMoment(token.expiresAt)}`
                                                : 'No expiry'}
                                        </p>
                                    </div>
                                </div>
                            ))
                        )}
                    </Widget>

                    <Widget
                        title="Due for rotation"
                        description="Secrets that have stood longer than the policy allows"
                        action={
                            staleSecrets.total > staleSecrets.rows.length ? (
                                <Badge variant="secondary">
                                    {staleSecrets.total} total
                                </Badge>
                            ) : undefined
                        }
                    >
                        {staleSecrets.rows.length === 0 ? (
                            <Empty>
                                Nothing overdue. A team without a rotation
                                policy never has anything here.
                            </Empty>
                        ) : (
                            staleSecrets.rows.map((secret) => (
                                <div
                                    key={`${secret.project ?? ''}/${secret.key}`}
                                    data-test="dashboard-stale-row"
                                    className="flex items-center justify-between gap-3 p-3"
                                >
                                    <div className="min-w-0">
                                        <p className="truncate text-sm font-medium">
                                            {secret.key}
                                        </p>
                                        <p className="truncate text-xs text-muted-foreground">
                                            {secret.project ?? 'No owner'} ·
                                            last changed{' '}
                                            {formatMoment(secret.rotatedAt)}
                                        </p>
                                    </div>
                                    <Badge
                                        variant="outline"
                                        className="text-destructive"
                                    >
                                        <Clock />
                                        {secret.overdueByDays}d
                                    </Badge>
                                </div>
                            ))
                        )}
                    </Widget>

                    {recentActivity === null ? null : (
                        <Widget
                            title="Recent activity"
                            description="The latest entries from the audit trail"
                            action={
                                <Button
                                    variant="secondary"
                                    size="sm"
                                    className="text-xs"
                                    asChild
                                >
                                    <Link href={audit(teamSlug)}>
                                        <ScrollText /> Full trail
                                    </Link>
                                </Button>
                            }
                        >
                            {recentActivity.length === 0 ? (
                                <Empty>Nothing recorded yet.</Empty>
                            ) : (
                                recentActivity.map((event) => (
                                    <div
                                        key={event.id}
                                        data-test="dashboard-activity-row"
                                        className="flex items-center justify-between gap-3 p-3"
                                    >
                                        <div className="min-w-0">
                                            <p className="truncate text-sm font-medium">
                                                {event.label}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                {event.actor ?? 'System'}
                                            </p>
                                        </div>
                                        <span className="text-xs whitespace-nowrap text-muted-foreground">
                                            {formatMoment(event.createdAt)}
                                        </span>
                                    </div>
                                ))
                            )}
                        </Widget>
                    )}
                </div>

                <Widget
                    title="How your variables are stored"
                    description="Envelope encryption, from the value you type to the row in the database"
                    action={
                        <Badge
                            variant="secondary"
                            data-test="encryption-scheme"
                        >
                            <Lock />
                            {encryption.cipher} · {encryption.scheme}
                        </Badge>
                    }
                >
                    <div
                        data-test="dashboard-encryption"
                        className="grid gap-1 sm:grid-cols-3"
                    >
                        <EncryptionStep
                            title="Encrypted before it is stored"
                            icon={Lock}
                        >
                            Every value is encrypted with {encryption.cipher}{' '}
                            the moment you save it, using a fresh nonce. The
                            database holds nothing but ciphertext and an
                            authentication tag, so an edited row fails to
                            decrypt instead of quietly handing back a different
                            value.
                        </EncryptionStep>
                        <EncryptionStep
                            title="A data key per team"
                            icon={KeyRound}
                        >
                            Values are encrypted with your team's own data key,
                            never with the master key. That data key is stored
                            wrapped and is only unwrapped in memory while a
                            request runs.
                            {encryption.keyVersion === null
                                ? ' Your team gets its key the first time you store a variable.'
                                : ` Your team is on key version ${encryption.keyVersion}, in use since ${formatMoment(encryption.keyCreatedAt)}.`}
                        </EncryptionStep>
                        <EncryptionStep
                            title="Rotating costs one row"
                            icon={RefreshCw}
                        >
                            Replacing the master key only rewrites the wrapped
                            data key, so not a single secret has to be
                            re-encrypted. Retired master keys keep opening older
                            payloads, which means a rotation needs no downtime.
                        </EncryptionStep>
                    </div>
                </Widget>
            </div>
        </>
    );
}

Dashboard.layout = (props: { currentTeam?: { slug: string } | null }) => ({
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: props.currentTeam ? dashboard(props.currentTeam.slug) : '/',
        },
    ],
});
