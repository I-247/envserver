import { Link, usePage } from '@inertiajs/react';
import { BookOpen, Boxes, Hammer, LayoutGrid, ScrollText } from 'lucide-react';
import { CommandPalette } from '@/components/command-palette';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { TeamSwitcher } from '@/components/team-switcher';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { audit, builtBy, dashboard } from '@/routes';
import { cli as cliDocs } from '@/routes/docs';
import { index as projectsIndex } from '@/routes/projects';
import type { NavItem } from '@/types';

export function AppSidebar() {
    const page = usePage();
    const dashboardUrl = page.props.currentTeam
        ? dashboard(page.props.currentTeam.slug)
        : '/';

    const mainNavItems: NavItem[] = [
        {
            title: 'Dashboard',
            href: dashboardUrl,
            icon: LayoutGrid,
        },
        ...(page.props.currentTeam
            ? [
                  {
                      title: 'Projects',
                      href: projectsIndex(page.props.currentTeam.slug),
                      icon: Boxes,
                  },
                  ...(page.props.currentTeam.role === 'owner' ||
                  page.props.currentTeam.role === 'admin'
                      ? [
                            {
                                title: 'Audit trail',
                                href: audit(page.props.currentTeam.slug),
                                icon: ScrollText,
                            },
                        ]
                      : []),
              ]
            : []),
    ];

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <TeamSwitcher />
                    </SidebarMenuItem>
                    <SidebarMenuItem>
                        <CommandPalette />
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton
                            asChild
                            size="sm"
                            tooltip="CLI documentation"
                            className="text-muted-foreground"
                        >
                            <Link href={cliDocs()}>
                                <BookOpen />
                                <span>CLI documentation</span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                    <SidebarMenuItem>
                        <SidebarMenuButton
                            asChild
                            size="sm"
                            tooltip="Built by Codecycler"
                            className="text-muted-foreground"
                        >
                            <Link href={builtBy()}>
                                <Hammer />
                                <span>Built by Codecycler</span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
