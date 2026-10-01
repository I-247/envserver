import { Link, router } from '@inertiajs/react';
import * as DropdownMenuPrimitive from '@radix-ui/react-dropdown-menu';
import type { LucideIcon } from 'lucide-react';
import { LogOut, Monitor, Moon, Settings, Sun } from 'lucide-react';
import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import { UserInfo } from '@/components/user-info';
import { useAppearance } from '@/hooks/use-appearance';
import type { Appearance } from '@/hooks/use-appearance';
import { useMobileNavigation } from '@/hooks/use-mobile-navigation';
import { cn } from '@/lib/utils';
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

/**
 * The three appearance choices as one full-width segmented control.
 *
 * Each segment is a real menu radio item, so arrow keys still reach it and a
 * screen reader still hears which one is checked. Selecting one keeps the
 * menu open, so you see the theme change without reopening it.
 */
function AppearanceSwitch({
    appearance,
    onChange,
}: {
    appearance: Appearance;
    onChange: (appearance: Appearance) => void;
}) {
    return (
        <div className="px-1 py-1">
            <DropdownMenuPrimitive.RadioGroup
                value={appearance}
                onValueChange={(value) => onChange(value as Appearance)}
                aria-label="Appearance"
                className="grid grid-cols-3 gap-0.5 rounded-md bg-muted p-0.5"
                data-test="appearance-switch"
            >
                {appearanceOptions.map(({ value, icon: Icon, label }) => (
                    <DropdownMenuPrimitive.RadioItem
                        key={value}
                        value={value}
                        onSelect={(event) => event.preventDefault()}
                        className={cn(
                            'flex h-7 cursor-pointer items-center justify-center gap-1.5 rounded-[5px] text-xs text-muted-foreground outline-hidden transition-colors',
                            'hover:text-foreground data-[highlighted]:text-foreground',
                            'data-[state=checked]:bg-background data-[state=checked]:text-foreground data-[state=checked]:shadow-xs',
                        )}
                    >
                        <Icon className="size-3.5" />
                        {label}
                    </DropdownMenuPrimitive.RadioItem>
                ))}
            </DropdownMenuPrimitive.RadioGroup>
        </div>
    );
}

export function UserMenuContent({ user }: Props) {
    const cleanup = useMobileNavigation();
    const { appearance, updateAppearance } = useAppearance();

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
                <AppearanceSwitch
                    appearance={appearance}
                    onChange={updateAppearance}
                />
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
