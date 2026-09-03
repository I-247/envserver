import { router, usePage } from '@inertiajs/react';
import { Check, ChevronsUpDown, Plus } from 'lucide-react';
import AppLogoIconColor from '@/components/app-logo-icon-color';
import CreateTeamModal from '@/components/create-team-modal';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { SidebarMenuButton } from '@/components/ui/sidebar';
import { useIsMobile } from '@/hooks/use-mobile';
import { switchMethod } from '@/routes/teams';
import type { Team } from '@/types';

type TeamSwitcherProps = {
    inHeader?: boolean;
};

export function TeamSwitcher({ inHeader = false }: TeamSwitcherProps) {
    const page = usePage();
    const isMobile = useIsMobile();
    const currentTeam = page.props.currentTeam;
    const teams = page.props.teams ?? [];
    const appName = page.props.name;

    const switchTeam = (team: Team) => {
        const previousTeamSlug = currentTeam?.slug;

        router.visit(switchMethod(team.slug), {
            onFinish: () => {
                if (!previousTeamSlug || typeof window === 'undefined') {
                    router.reload();

                    return;
                }

                const currentUrl = `${window.location.pathname}${window.location.search}${window.location.hash}`;
                const segment = `/${previousTeamSlug}`;

                if (currentUrl.includes(segment)) {
                    router.visit(currentUrl.replace(segment, `/${team.slug}`), {
                        replace: true,
                    });

                    return;
                }

                router.reload();
            },
        });
    };

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                {inHeader ? (
                    <Button
                        variant="ghost"
                        data-test="team-switcher-trigger"
                        className="h-8 gap-1 px-2"
                    >
                        <div className="grid flex-1 text-left text-sm leading-tight">
                            <span className="max-w-[120px] truncate font-medium">
                                {currentTeam?.name ?? 'Select team'}
                            </span>
                        </div>
                        <ChevronsUpDown className="size-4 opacity-50" />
                    </Button>
                ) : (
                    <SidebarMenuButton
                        size="lg"
                        data-test="team-switcher-trigger"
                        className="data-[state=open]:bg-sidebar-accent data-[state=open]:text-sidebar-accent-foreground"
                    >
                        <div className="flex aspect-square size-8 items-center justify-center">
                            <AppLogoIconColor className="size-8" />
                        </div>
                        <div className="grid flex-1 text-left text-sm leading-tight">
                            <span className="truncate font-semibold">
                                {appName}
                            </span>
                            <span className="truncate text-xs text-muted-foreground">
                                {currentTeam?.name ?? 'Select team'}
                            </span>
                        </div>
                        <ChevronsUpDown className="ml-auto size-4" />
                    </SidebarMenuButton>
                )}
            </DropdownMenuTrigger>
            <DropdownMenuContent
                className={
                    inHeader
                        ? 'w-56'
                        : 'w-(--radix-dropdown-menu-trigger-width) min-w-56 rounded-lg'
                }
                side={inHeader ? undefined : isMobile ? 'bottom' : 'right'}
                align={inHeader ? 'end' : 'start'}
                sideOffset={inHeader ? undefined : 4}
            >
                <DropdownMenuLabel className="text-xs text-muted-foreground">
                    Teams
                </DropdownMenuLabel>
                {teams.map((team) => (
                    <DropdownMenuItem
                        key={team.id}
                        data-test="team-switcher-item"
                        className={
                            inHeader
                                ? 'cursor-pointer gap-2'
                                : 'cursor-pointer gap-2 p-2'
                        }
                        onSelect={() => switchTeam(team)}
                    >
                        {team.name}
                        {currentTeam?.id === team.id && (
                            <Check
                                className={
                                    inHeader
                                        ? 'ml-auto size-4'
                                        : 'ml-auto h-4 w-4'
                                }
                            />
                        )}
                    </DropdownMenuItem>
                ))}
                <DropdownMenuSeparator />
                <CreateTeamModal>
                    <DropdownMenuItem
                        data-test="team-switcher-new-team"
                        className={
                            inHeader
                                ? 'cursor-pointer gap-2'
                                : 'cursor-pointer gap-2 p-2'
                        }
                        onSelect={(event) => event.preventDefault()}
                    >
                        <Plus className={inHeader ? 'size-4' : 'h-4 w-4'} />
                        <span className="text-muted-foreground">New team</span>
                    </DropdownMenuItem>
                </CreateTeamModal>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
