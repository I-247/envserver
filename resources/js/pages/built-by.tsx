import { Head } from '@inertiajs/react';
import { ArrowUpRight } from 'lucide-react';
import { Button } from '@/components/ui/button';

const codecyclerUrl = 'https://codecycler.com';

const highlights = [
    {
        title: 'Laravel and React',
        description:
            'A Laravel backend with an Inertia and React frontend, covered by Pest tests.',
    },
    {
        title: 'Encrypted at rest',
        description:
            'Every value is sealed with AES-GCM before it reaches the database, with a full version history.',
    },
    {
        title: 'A CLI for deploys',
        description:
            'envclient is written in Go and pulls variables onto servers with scoped deploy tokens.',
    },
    {
        title: 'Signed releases',
        description:
            'CLI releases are signed with cosign, and self-updates refuse checksums that are not.',
    },
];

export default function BuiltBy() {
    return (
        <>
            <Head title="Built by Codecycler" />

            <div className="flex flex-col gap-16">
                <section className="flex flex-col gap-6">
                    <p className="text-sm font-medium tracking-wide text-muted-foreground uppercase">
                        Built by Codecycler
                    </p>
                    <h1 className="max-w-2xl text-4xl font-semibold tracking-tight text-balance md:text-5xl">
                        Envserver is designed and developed by Codecycler.
                    </h1>
                    <p className="max-w-2xl text-lg text-muted-foreground">
                        Codecycler is the company of Sebastiaan Kloos, based in
                        Eerbeek, the Netherlands. It builds web applications
                        with Laravel, from internal tools like this one to
                        complete platforms.
                    </p>

                    <div>
                        <Button size="lg" asChild>
                            <a
                                href={codecyclerUrl}
                                target="_blank"
                                rel="noopener"
                            >
                                Visit codecycler.com
                                <ArrowUpRight />
                            </a>
                        </Button>
                    </div>
                </section>

                <section className="flex flex-col gap-6">
                    <h2 className="text-xl font-semibold">
                        What went into Envserver
                    </h2>
                    <div className="grid gap-4 sm:grid-cols-2">
                        {highlights.map((highlight) => (
                            <div
                                key={highlight.title}
                                className="rounded-xl border border-sidebar-border/70 p-6 dark:border-sidebar-border"
                            >
                                <h3 className="font-medium">
                                    {highlight.title}
                                </h3>
                                <p className="mt-2 text-sm text-muted-foreground">
                                    {highlight.description}
                                </p>
                            </div>
                        ))}
                    </div>
                </section>

                <section className="flex flex-col items-start gap-4 rounded-xl bg-muted p-8">
                    <h2 className="text-xl font-semibold">
                        Need an application like this?
                    </h2>
                    <p className="max-w-xl text-muted-foreground">
                        Codecycler takes on custom Laravel projects. See what
                        Codecycler builds and how to start a project on
                        codecycler.com.
                    </p>
                    <Button asChild>
                        <a href={codecyclerUrl} target="_blank" rel="noopener">
                            Visit codecycler.com
                            <ArrowUpRight />
                        </a>
                    </Button>
                </section>
            </div>
        </>
    );
}
