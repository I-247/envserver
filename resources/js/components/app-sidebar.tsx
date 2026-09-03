import { usePage } from '@inertiajs/react';
import { Boxes, LayoutGrid, ScrollText } from 'lucide-react';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { TeamSwitcher } from '@/components/team-switcher';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { audit, dashboard } from '@/routes';
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
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
