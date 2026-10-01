import { router, usePage } from '@inertiajs/react';
import { Command } from 'cmdk';
import {
    BookOpen,
    Check,
    Plus,
    Boxes,
    KeyRound,
    Layers,
    LayoutGrid,
    Loader2,
    ScrollText,
    Search,
    Settings,
    ShieldCheck,
    Users,
    Variable,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import CreateTeamModal from '@/components/create-team-modal';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { SidebarMenuButton } from '@/components/ui/sidebar';
import { useSwitchTeam } from '@/hooks/use-switch-team';
import { audit, dashboard, search as searchRoute } from '@/routes';
import { cli as cliDocs } from '@/routes/docs';
import { edit as profileEdit } from '@/routes/profile';
import { index as projectsIndex } from '@/routes/projects';
import { edit as securityEdit } from '@/routes/security';
import { index as teamsIndex } from '@/routes/teams';

type SearchResult = {
    title: string;
    subtitle: string;
    url: string;
};

type SearchResults = {
    projects: SearchResult[];
    environments: SearchResult[];
    variables: SearchResult[];
    deployTokens: SearchResult[];
};

type PageLink = {
    title: string;
    url: string;
    icon: LucideIcon;
};

/**
 * Below this many characters only pages are searched: one letter matches
 * half the team, and the server refuses it anyway.
 */
const MIN_QUERY_LENGTH = 2;

/**
 * Wait this long after the last keystroke before asking the server.
 */
const DEBOUNCE_MS = 200;

const resultGroups: {
    key: keyof SearchResults;
    heading: string;
    icon: LucideIcon;
}[] = [
    { key: 'projects', heading: 'Projects', icon: Boxes },
    { key: 'environments', heading: 'Environments', icon: Layers },
    { key: 'variables', heading: 'Variables', icon: Variable },
    { key: 'deployTokens', heading: 'Deploy tokens', icon: KeyRound },
];

/**
 * Open the palette with ⌘K on a Mac and Ctrl+K elsewhere.
 */
function useShortcut(onToggle: () => void): void {
    useEffect(() => {
        const handle = (event: KeyboardEvent) => {
            if (
                event.key.toLowerCase() === 'k' &&
                (event.metaKey || event.ctrlKey)
            ) {
                event.preventDefault();
                onToggle();
            }
        };

        window.addEventListener('keydown', handle);

        return () => window.removeEventListener('keydown', handle);
    }, [onToggle]);
}

/**
 * Ask the server what in the team matches, once typing pauses.
 *
 * A newer query aborts the request for an older one, so a slow answer can
 * never overwrite the results for what is in the box now.
 */
function useTeamSearch(
    teamSlug: string | null,
    query: string,
): { results: SearchResults | null; loading: boolean } {
    const [results, setResults] = useState<SearchResults | null>(null);
    const [loading, setLoading] = useState(false);
    const trimmed = query.trim();
    const enabled = teamSlug !== null && trimmed.length >= MIN_QUERY_LENGTH;

    useEffect(() => {
        if (!enabled) {
            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(async () => {
            setLoading(true);

            try {
                const response = await fetch(
                    searchRoute.url(teamSlug, { query: { q: trimmed } }),
                    {
                        headers: { Accept: 'application/json' },
                        signal: controller.signal,
                    },
                );

                if (response.ok) {
                    setResults(await response.json());
                }
            } catch {
                // Aborted by a newer query, or offline: keep what is shown.
            } finally {
                if (!controller.signal.aborted) {
                    setLoading(false);
                }
            }
        }, DEBOUNCE_MS);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [enabled, teamSlug, trimmed]);

    return {
        results: enabled ? results : null,
        loading: enabled && loading,
    };
}

export function CommandPalette() {
    const { currentTeam, teams = [] } = usePage().props;
    const switchTeam = useSwitchTeam();
    const [creatingTeam, setCreatingTeam] = useState(false);
    const teamSlug = currentTeam?.slug ?? null;
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');

    useShortcut(() => setOpen((isOpen) => !isOpen));

    const { results, loading } = useTeamSearch(open ? teamSlug : null, query);

    const pages = useMemo<PageLink[]>(() => {
        const teamPages: PageLink[] = currentTeam
            ? [
                  {
                      title: 'Dashboard',
                      url: dashboard(currentTeam.slug).url,
                      icon: LayoutGrid,
                  },
                  {
                      title: 'Projects',
                      url: projectsIndex(currentTeam.slug).url,
                      icon: Boxes,
                  },
                  ...(currentTeam.role === 'owner' ||
                  currentTeam.role === 'admin'
                      ? [
                            {
                                title: 'Audit trail',
                                url: audit(currentTeam.slug).url,
                                icon: ScrollText,
                            },
                        ]
                      : []),
              ]
            : [];

        return [
            ...teamPages,
            {
                title: 'Profile settings',
                url: profileEdit().url,
                icon: Settings,
            },
            {
                title: 'Security settings',
                url: securityEdit().url,
                icon: ShieldCheck,
            },
            { title: 'Manage teams', url: teamsIndex().url, icon: Users },
            { title: 'CLI documentation', url: cliDocs().url, icon: BookOpen },
        ];
    }, [currentTeam]);

    const needle = query.trim().toLowerCase();
    const matchingPages = needle
        ? pages.filter((page) => page.title.toLowerCase().includes(needle))
        : pages;

    const matchingTeams = needle
        ? teams.filter((team) => team.name.toLowerCase().includes(needle))
        : teams;

    // Shown for an empty box too, and for anything that reads like asking
    // for it: "new", "create", "team".
    const showCreateTeam =
        !needle ||
        ['new team', 'create team'].some(
            (phrase) => phrase.includes(needle) || needle.includes(phrase),
        );

    const hasResults =
        matchingPages.length > 0 ||
        matchingTeams.length > 0 ||
        showCreateTeam ||
        resultGroups.some((group) => (results?.[group.key].length ?? 0) > 0);

    const selectTeam = (team: { slug: string }) => {
        setOpen(false);
        setQuery('');

        if (team.slug !== currentTeam?.slug) {
            switchTeam(team);
        }
    };

    const createTeam = () => {
        setOpen(false);
        setQuery('');
        setCreatingTeam(true);
    };

    const go = (url: string) => {
        setOpen(false);
        router.visit(url);
    };

    const changeOpen = (isOpen: boolean) => {
        setOpen(isOpen);

        if (!isOpen) {
            setQuery('');
        }
    };

    return (
        <>
            <SidebarMenuButton
                onClick={() => setOpen(true)}
                tooltip="Search (⌘K)"
                className="border border-sidebar-border/70 text-muted-foreground"
                data-test="command-palette-trigger"
            >
                <Search />
                <span>Search</span>
                <kbd className="pointer-events-none ml-auto rounded border bg-sidebar px-1.5 font-mono text-[10px] group-data-[collapsible=icon]:hidden">
                    ⌘K
                </kbd>
            </SidebarMenuButton>

            <Dialog open={open} onOpenChange={changeOpen}>
                <DialogContent className="gap-0 overflow-hidden p-0 sm:max-w-xl [&>button]:hidden">
                    <DialogTitle className="sr-only">Search</DialogTitle>
                    <DialogDescription className="sr-only">
                        Search projects, environments, variable keys, deploy
                        tokens and pages.
                    </DialogDescription>

                    {/* Filtering happens here and on the server, not in cmdk:
                        its fuzzy ranking would reorder results the server
                        already chose and limited. */}
                    <Command shouldFilter={false} loop>
                        <div className="flex items-center gap-2 border-b px-4">
                            <Search className="size-4 shrink-0 text-muted-foreground" />
                            <Command.Input
                                value={query}
                                onValueChange={setQuery}
                                placeholder="Search projects, environments, keys…"
                                className="h-12 w-full bg-transparent text-sm outline-none placeholder:text-muted-foreground"
                                data-test="command-palette-input"
                            />
                            {loading ? (
                                <Loader2 className="size-4 shrink-0 animate-spin text-muted-foreground" />
                            ) : null}
                        </div>

                        <Command.List className="max-h-96 overflow-y-auto p-2">
                            {!hasResults && !loading ? (
                                <p className="px-2 py-6 text-center text-sm text-muted-foreground">
                                    Nothing matches &ldquo;{query.trim()}
                                    &rdquo;.
                                </p>
                            ) : null}

                            {matchingPages.length > 0 ? (
                                <PaletteGroup heading="Pages">
                                    {matchingPages.map((page) => (
                                        <PaletteItem
                                            key={page.url}
                                            value={`page:${page.url}`}
                                            icon={page.icon}
                                            title={page.title}
                                            onSelect={() => go(page.url)}
                                        />
                                    ))}
                                </PaletteGroup>
                            ) : null}

                            {matchingTeams.length > 0 ? (
                                <PaletteGroup heading="Teams">
                                    {matchingTeams.map((team) => (
                                        <PaletteItem
                                            key={team.slug}
                                            value={`team:${team.slug}`}
                                            icon={Users}
                                            title={team.name}
                                            subtitle={
                                                team.slug === currentTeam?.slug
                                                    ? 'Current team'
                                                    : 'Switch to this team'
                                            }
                                            trailing={
                                                team.slug ===
                                                currentTeam?.slug ? (
                                                    <Check className="size-4" />
                                                ) : null
                                            }
                                            onSelect={() => selectTeam(team)}
                                        />
                                    ))}
                                </PaletteGroup>
                            ) : null}

                            {showCreateTeam ? (
                                <PaletteGroup heading="Actions">
                                    <PaletteItem
                                        value="action:create-team"
                                        icon={Plus}
                                        title="Create new team"
                                        onSelect={createTeam}
                                    />
                                </PaletteGroup>
                            ) : null}

                            {resultGroups.map((group) =>
                                results && results[group.key].length > 0 ? (
                                    <PaletteGroup
                                        key={group.key}
                                        heading={group.heading}
                                    >
                                        {results[group.key].map((result) => (
                                            <PaletteItem
                                                key={`${group.key}:${result.title}:${result.url}`}
                                                value={`${group.key}:${result.title}:${result.url}`}
                                                icon={group.icon}
                                                title={result.title}
                                                subtitle={result.subtitle}
                                                onSelect={() => go(result.url)}
                                            />
                                        ))}
                                    </PaletteGroup>
                                ) : null,
                            )}
                        </Command.List>
                    </Command>
                </DialogContent>
            </Dialog>

            <CreateTeamModal
                open={creatingTeam}
                onOpenChange={setCreatingTeam}
            />
        </>
    );
}

function PaletteGroup({
    heading,
    children,
}: {
    heading: string;
    children: React.ReactNode;
}) {
    return (
        <Command.Group
            heading={heading}
            className="[&_[cmdk-group-heading]]:px-2 [&_[cmdk-group-heading]]:pt-2 [&_[cmdk-group-heading]]:pb-1 [&_[cmdk-group-heading]]:text-xs [&_[cmdk-group-heading]]:font-medium [&_[cmdk-group-heading]]:text-muted-foreground"
        >
            {children}
        </Command.Group>
    );
}

function PaletteItem({
    value,
    icon: Icon,
    title,
    subtitle,
    trailing,
    onSelect,
}: {
    value: string;
    icon: LucideIcon;
    title: string;
    subtitle?: string;
    trailing?: React.ReactNode;
    onSelect: () => void;
}) {
    return (
        <Command.Item
            value={value}
            onSelect={onSelect}
            className="flex cursor-pointer items-center gap-3 rounded-md px-2 py-2 text-sm data-[selected=true]:bg-accent data-[selected=true]:text-accent-foreground"
            data-test="command-palette-item"
        >
            <Icon className="size-4 shrink-0 text-muted-foreground" />
            <span className="truncate">{title}</span>
            {subtitle ? (
                <span className="ml-auto truncate text-xs text-muted-foreground">
                    {subtitle}
                </span>
            ) : null}
            {trailing ? (
                <span className={subtitle ? 'shrink-0' : 'ml-auto shrink-0'}>
                    {trailing}
                </span>
            ) : null}
        </Command.Item>
    );
}
