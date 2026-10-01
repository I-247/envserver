import { router, usePage } from '@inertiajs/react';
import { switchMethod } from '@/routes/teams';

/**
 * Switch to another team and stay on the same page there when possible.
 *
 * A URL that names the previous team, like /acme/projects, is reopened for
 * the new one (/globex/projects). Anything else is simply reloaded.
 */
export function useSwitchTeam(): (team: { slug: string }) => void {
    const previousTeamSlug = usePage().props.currentTeam?.slug;

    return (team) => {
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
}
