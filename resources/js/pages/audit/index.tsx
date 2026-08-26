import { Head, router, usePage } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, Search, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import Code from '@/components/code';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
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

type FilterOption = {
    value: string;
    label: string;
};

type Filters = {
    actor: string | null;
    action: string | null;
    search: string | null;
};

type Pagination = {
    currentPage: number;
    lastPage: number;
    perPage: number;
    total: number;
    from: number | null;
    to: number | null;
};

type Props = {
    events: AuditEventRow[];
    pagination: Pagination;
    filters: Filters;
    actors: FilterOption[];
    actions: FilterOption[];
};

/**
 * A Radix select item cannot hold an empty value, so "no filter" travels as a
 * sentinel and is translated back into an absent query parameter.
 */
const ANY = 'any';

function describe(metadata: Record<string, unknown> | null): string {
    if (!metadata) {
        return '';
    }

    return Object.entries(metadata)
        .map(([key, value]) => `${key}=${String(value)}`)
        .join('  ');
}

export default function AuditIndex({
    events,
    pagination,
    filters,
    actors,
    actions,
}: Props) {
    const page = usePage();
    const teamSlug = page.props.currentTeam?.slug ?? '';
    const [search, setSearch] = useState(filters.search ?? '');
    const isFiltered =
        filters.actor !== null ||
        filters.action !== null ||
        filters.search !== null;

    /**
     * The filters and the page live in the URL so a filtered trail can be
     * shared and survives a refresh. Partial reloads keep the request to the
     * table only. Leaving out the page number is what puts a changed filter
     * back at the newest events instead of on a page that may not exist.
     */
    const visit = (next: Filters, pageNumber?: number) => {
        router.get(
            audit(teamSlug).url,
            {
                ...(next.actor ? { actor: next.actor } : {}),
                ...(next.action ? { action: next.action } : {}),
                ...(next.search ? { search: next.search } : {}),
                ...(pageNumber && pageNumber > 1 ? { page: pageNumber } : {}),
            },
            {
                only: ['events', 'pagination', 'filters'],
                preserveState: true,
                // Paging jumps back to the top of the table; changing a filter
                // leaves you where you were.
                preserveScroll: pageNumber === undefined,
                replace: true,
            },
        );
    };

    // Debounced so typing in the details search does not fire a request per
    // keystroke.
    useEffect(() => {
        const trimmed = search.trim();

        if (trimmed === (filters.search ?? '')) {
            return;
        }

        const timeout = window.setTimeout(() => {
            router.get(
                audit(teamSlug).url,
                {
                    ...(filters.actor ? { actor: filters.actor } : {}),
                    ...(filters.action ? { action: filters.action } : {}),
                    ...(trimmed === '' ? {} : { search: trimmed }),
                },
                {
                    only: ['events', 'pagination', 'filters'],
                    preserveState: true,
                    preserveScroll: true,
                    replace: true,
                },
            );
        }, 300);

        return () => window.clearTimeout(timeout);
    }, [search, filters.search, filters.actor, filters.action, teamSlug]);

    const clearFilters = () => {
        setSearch('');
        visit({ actor: null, action: null, search: null });
    };

    return (
        <>
            <Head title="Audit trail" />

            <div className="flex flex-col space-y-6 p-4">
                <Heading
                    variant="small"
                    title="Audit trail"
                    description="Who did what, including who looked at a secret. Values are never recorded here."
                />

                <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                    <Select
                        value={filters.actor ?? ANY}
                        onValueChange={(value) =>
                            visit({
                                ...filters,
                                actor: value === ANY ? null : value,
                            })
                        }
                    >
                        <SelectTrigger
                            data-test="audit-filter-actor"
                            className="sm:w-56"
                        >
                            <SelectValue placeholder="Anyone" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ANY}>Anyone</SelectItem>
                            {actors.map((actor) => (
                                <SelectItem
                                    key={actor.value}
                                    value={actor.value}
                                >
                                    {actor.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <Select
                        value={filters.action ?? ANY}
                        onValueChange={(value) =>
                            visit({
                                ...filters,
                                action: value === ANY ? null : value,
                            })
                        }
                    >
                        <SelectTrigger
                            data-test="audit-filter-action"
                            className="sm:w-72"
                        >
                            <SelectValue placeholder="Anything" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ANY}>Anything</SelectItem>
                            {actions.map((action) => (
                                <SelectItem
                                    key={action.value}
                                    value={action.value}
                                >
                                    {action.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <div className="relative flex-1">
                        <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            data-test="audit-filter-search"
                            className="pl-9"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Search the details, for example DB_PASSWORD"
                        />
                    </div>

                    {isFiltered ? (
                        <Button
                            variant="ghost"
                            data-test="audit-filter-clear"
                            onClick={clearFilters}
                        >
                            <X /> Clear
                        </Button>
                    ) : null}
                </div>

                {events.length === 0 ? (
                    <div className="rounded-lg border border-dashed p-12 text-center">
                        <p className="font-medium">
                            {isFiltered
                                ? 'Nothing matches these filters'
                                : 'Nothing recorded yet'}
                        </p>
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
                                        <td className="p-3">
                                            <Code className="text-xs text-muted-foreground">
                                                {describe(event.metadata)}
                                            </Code>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {pagination.total > 0 ? (
                    <div className="flex flex-col items-center justify-between gap-2 sm:flex-row">
                        <p
                            className="text-sm text-muted-foreground"
                            data-test="audit-pagination-summary"
                        >
                            Showing {pagination.from}&ndash;{pagination.to} of{' '}
                            {pagination.total}{' '}
                            {pagination.total === 1 ? 'event' : 'events'}
                        </p>

                        {pagination.lastPage > 1 ? (
                            <div className="flex items-center gap-2">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    data-test="audit-previous-page"
                                    disabled={pagination.currentPage <= 1}
                                    onClick={() =>
                                        visit(
                                            filters,
                                            pagination.currentPage - 1,
                                        )
                                    }
                                >
                                    <ChevronLeft /> Newer
                                </Button>

                                <span className="text-sm text-muted-foreground">
                                    Page {pagination.currentPage} of{' '}
                                    {pagination.lastPage}
                                </span>

                                <Button
                                    variant="outline"
                                    size="sm"
                                    data-test="audit-next-page"
                                    disabled={
                                        pagination.currentPage >=
                                        pagination.lastPage
                                    }
                                    onClick={() =>
                                        visit(
                                            filters,
                                            pagination.currentPage + 1,
                                        )
                                    }
                                >
                                    Older <ChevronRight />
                                </Button>
                            </div>
                        ) : null}
                    </div>
                ) : null}
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
