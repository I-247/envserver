import { Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import type { ReactNode } from 'react';
import AppLogoIconColor from '@/components/app-logo-icon-color';
import { Button } from '@/components/ui/button';
import { builtBy, home } from '@/routes';

/**
 * The frame for pages a visitor may see without an account: the credits page
 * and the CLI documentation. No sidebar, because there may be no team.
 */
export default function PublicLayout({ children }: { children: ReactNode }) {
    return (
        <div className="flex min-h-svh flex-col bg-background">
            <header className="sticky top-0 z-20 border-b border-transparent bg-background/90 backdrop-blur supports-[backdrop-filter]:bg-background/75">
                <div className="mx-auto flex w-full max-w-5xl items-center justify-between px-4 py-4 md:px-6">
                    <Link
                        href={home()}
                        className="flex items-center gap-2 text-sm font-medium"
                    >
                        <AppLogoIconColor className="size-8" />
                        <span>Envserver</span>
                    </Link>

                    <Button variant="ghost" size="sm" asChild>
                        <Link href={home()}>
                            <ArrowLeft />
                            Back to the app
                        </Link>
                    </Button>
                </div>
            </header>

            <main className="mx-auto flex w-full max-w-5xl flex-1 flex-col px-4 py-12 md:px-6 md:py-16">
                {children}
            </main>

            <footer className="mx-auto w-full max-w-5xl px-4 py-8 text-sm text-muted-foreground md:px-6">
                © {new Date().getFullYear()}{' '}
                <Link
                    href={builtBy()}
                    className="transition-colors hover:text-foreground"
                >
                    Codecycler
                </Link>
            </footer>
        </div>
    );
}
