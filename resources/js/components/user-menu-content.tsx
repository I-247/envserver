import { Link, router } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import { LogOut, Monitor, Moon, Settings, Sun } from 'lucide-react';
import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuSub,
    DropdownMenuSubContent,
    DropdownMenuSubTrigger,
} from '@/components/ui/dropdown-menu';
import { UserInfo } from '@/components/user-info';
import { useAppearance } from '@/hooks/use-appearance';
import type { Appearance } from '@/hooks/use-appearance';
import { useMobileNavigation } from '@/hooks/use-mobile-navigation';
import { logout } from '@/routes';
import { edit } from '@/routes/profile';
import type { User } from '@/types';

type Props = {
    user: User;
};

const appearanceOptions: {
    value: Appearance;
    icon: LucideIcon;
    label: string;
}[] = [
    { value: 'light', icon: Sun, label: 'Light' },
    { value: 'dark', icon: Moon, label: 'Dark' },
    { value: 'system', icon: Monitor, label: 'System' },
];

const appearanceRadioItemClassName =
    'data-[state=checked]:bg-accent data-[state=checked]:text-accent-foreground data-[state=checked]:font-medium';

export function UserMenuContent({ user }: Props) {
    const cleanup = useMobileNavigation();
    const { appearance, updateAppearance } = useAppearance();
    const AppearanceIcon =
        appearanceOptions.find((option) => option.value === appearance)?.icon ??
        Sun;

    const handleLogout = () => {
        cleanup();
        router.flushAll();
    };

    return (
        <>
            <DropdownMenuLabel className="p-0 font-normal">
                <div className="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
                    <UserInfo user={user} showEmail={true} />
                </div>
            </DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuGroup>
                <DropdownMenuItem asChild>
                    <Link
                        className="block w-full cursor-pointer"
                        href={edit()}
                        prefetch
                        onClick={cleanup}
                    >
                        <Settings className="mr-2" />
                        Settings
                    </Link>
                </DropdownMenuItem>
                <DropdownMenuSub>
                    <DropdownMenuSubTrigger>
                        <AppearanceIcon className="mr-2" />
                        Appearance
                    </DropdownMenuSubTrigger>
                    <DropdownMenuSubContent>
                        <DropdownMenuRadioGroup
                            value={appearance}
                            onValueChange={(value) =>
                                updateAppearance(value as Appearance)
                            }
                        >
                            {appearanceOptions.map(
                                ({ value, icon: Icon, label }) => (
                                    <DropdownMenuRadioItem
                                        key={value}
                                        value={value}
                                        className={appearanceRadioItemClassName}
                                    >
                                        <Icon className="mr-2" />
                                        {label}
                                    </DropdownMenuRadioItem>
                                ),
                            )}
                        </DropdownMenuRadioGroup>
                    </DropdownMenuSubContent>
                </DropdownMenuSub>
            </DropdownMenuGroup>
            <DropdownMenuSeparator />
            <DropdownMenuItem asChild>
                <Link
                    className="block w-full cursor-pointer"
                    href={logout()}
                    as="button"
                    onClick={handleLogout}
                    data-test="logout-button"
                >
                    <LogOut className="mr-2" />
                    Log out
                </Link>
            </DropdownMenuItem>
        </>
    );
}
