import { Head } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import Code from '@/components/code';
import CopyButton from '@/components/copy-button';
import { cn } from '@/lib/utils';

type Props = {
    server: string;
};

type Flag = {
    name: string;
    description: string;
};

type Command = {
    usage: string;
    description: string;
    flags: Flag[];
};

const INSTALL_SCRIPT =
    'https://raw.githubusercontent.com/I-247/envserver/main/cli/scripts/install.sh';

const sections = [
    { id: 'install', title: 'Install' },
    { id: 'link', title: 'Link a project' },
    { id: 'day-to-day', title: 'Day to day' },
    { id: 'deploy-server', title: 'On a deploy server' },
    { id: 'ci', title: 'In CI' },
    { id: 'vault', title: 'Encrypted locally' },
    { id: 'updating', title: 'Updating' },
    { id: 'commands', title: 'Commands' },
    { id: 'environment-variables', title: 'Environment variables' },
    { id: 'exit-codes', title: 'Exit codes' },
    { id: 'troubleshooting', title: 'Troubleshooting' },
];

/**
 * Every command and flag, as `envclient <command> --help` prints them.
 * Keep this in step with cli/README.md when a command changes.
 */
const commands: Command[] = [
    {
        usage: 'envclient init',
        description:
            'Link this directory to an environment by writing envclient.json.',
        flags: [
            { name: '--server', description: 'the Envserver server URL' },
            { name: '--team', description: 'the team slug' },
            { name: '--project', description: 'the project slug' },
            {
                name: '--environment',
                description: 'the environment slug (default "development")',
            },
        ],
    },
    {
        usage: 'envclient login',
        description:
            'Log in from your terminal. You get a code, and approve it in the browser.',
        flags: [
            {
                name: '--server',
                description:
                    'the server to log in to (default: the one in envclient.json)',
            },
        ],
    },
    {
        usage: 'envclient logout',
        description: 'Remove the stored token for a server.',
        flags: [
            {
                name: '--server',
                description:
                    'the server to log out of (default: the one in envclient.json)',
            },
        ],
    },
    {
        usage: 'envclient whoami',
        description:
            'Show which server you are logged in to and what you can reach.',
        flags: [],
    },
    {
        usage: 'envclient list',
        description: 'List the projects and environments you can reach.',
        flags: [],
    },
    {
        usage: 'envclient pull',
        description:
            'Write the published variables into your .env. Shows what would change and asks first.',
        flags: [
            {
                name: '--constructive',
                description: 'also add keys that are not in your file yet',
            },
            {
                name: '--prune',
                description:
                    'also delete keys your file has and the release does not',
            },
            {
                name: '--dry-run',
                description: 'report what would change and write nothing',
            },
            {
                name: '--force',
                description:
                    'apply without asking, and write even when the file does not exist',
            },
            {
                name: '-o, --out',
                description:
                    'the file to write (default: .env next to envclient.json)',
            },
            {
                name: '--release',
                description: 'pull a specific release instead of the latest',
            },
        ],
    },
    {
        usage: 'envclient push',
        description: 'Send the values in your .env to the server.',
        flags: [
            {
                name: '-m, --message',
                description: 'message for the published release',
            },
            {
                name: '--publish',
                description:
                    'publish a release straight after pushing (personal login only)',
            },
            {
                name: '-f, --file',
                description:
                    'the file to read (default: .env next to envclient.json)',
            },
        ],
    },
    {
        usage: 'envclient diff',
        description: 'Compare your .env with the latest release.',
        flags: [
            {
                name: '-f, --file',
                description:
                    'the file to compare (default: .env next to envclient.json)',
            },
        ],
    },
    {
        usage: 'envclient check',
        description:
            'The same comparison as diff, but exits 2 when your file is missing something.',
        flags: [
            {
                name: '-f, --file',
                description:
                    'the file to check (default: .env next to envclient.json)',
            },
            {
                name: '--strict',
                description: 'also fail on keys that exist only in your file',
            },
        ],
    },
    {
        usage: 'envclient history',
        description: 'Show the release history of this environment.',
        flags: [],
    },
    {
        usage: 'envclient run -- <command> [args...]',
        description:
            'Run a command with the variables injected, without writing a file.',
        flags: [
            {
                name: '--vault',
                description: 'only use the local sealed file, never the server',
            },
            {
                name: '--remote',
                description: 'use the server even when a sealed file exists',
            },
            {
                name: '-f, --file',
                description:
                    'the sealed file to read (default: .env.envclient next to envclient.json)',
            },
            {
                name: '--release',
                description: 'use a specific release instead of the latest',
            },
        ],
    },
    {
        usage: 'envclient seal',
        description:
            'Store the release locally as an encrypted file (.env.envclient).',
        flags: [
            {
                name: '-f, --file',
                description:
                    'where to write the sealed file (default: .env.envclient next to envclient.json)',
            },
            {
                name: '--release',
                description: 'seal a specific release instead of the latest',
            },
        ],
    },
    {
        usage: 'envclient unseal',
        description: 'Decrypt the local vault and show what is in it.',
        flags: [
            {
                name: '-f, --file',
                description:
                    'the sealed file to read (default: .env.envclient next to envclient.json)',
            },
            {
                name: '-o, --out',
                description:
                    'write the variables to this file instead of the terminal',
            },
        ],
    },
    {
        usage: 'envclient update',
        description: 'Update envclient itself to the latest signed release.',
        flags: [
            {
                name: '--check',
                description: 'only report whether an update is available',
            },
            {
                name: '--force',
                description:
                    'skip the confirmation (and reinstall even if already current)',
            },
        ],
    },
];

const environmentVariables: Flag[] = [
    {
        name: 'ENVCLIENT_SERVER',
        description:
            'The server a deploy token belongs to. Required next to a deploy token; the secret is never sent anywhere else.',
    },
    {
        name: 'ENVCLIENT_CLIENT_ID',
        description:
            'The deploy token’s client ID. Takes priority over a stored personal login.',
    },
    {
        name: 'ENVCLIENT_CLIENT_SECRET',
        description:
            'The deploy token’s secret. Also the key a sealed file is locked with.',
    },
    {
        name: 'ENVCLIENT_SCOPES',
        description:
            'The scopes asked for with a deploy token (default "env:read env:write"). The token itself decides what it may do, so leave this alone.',
    },
    {
        name: 'ENVCLIENT_VAULT_KEY',
        description:
            'Your own key for envclient seal and run, for a machine without a deploy token. At least 16 bytes.',
    },
    {
        name: 'ENVCLIENT_CONFIG_DIR',
        description:
            'Where credentials.json is kept after envclient login. Defaults to your user config directory.',
    },
    {
        name: 'NO_COLOR',
        description: 'Turns colour off, like --no-color.',
    },
    {
        name: 'CLICOLOR_FORCE',
        description:
            'Turns colour on when the output is piped, for a CI runner.',
    },
];

const troubleshooting: { problem: string; answer: ReactNode }[] = [
    {
        problem:
            'this pull needs confirmation and there is no terminal to ask at',
        answer: (
            <>
                The pull ran without a terminal, as on a deploy server or in CI.
                Add <Code>--force</Code> to apply it, or <Code>--dry-run</Code>{' '}
                to only look.
            </>
        ),
    },
    {
        problem: 'A deploy does not show up in the portal',
        answer: (
            <>
                Only a pull with a deploy token counts as a deploy. A pull with{' '}
                <Code>envclient login</Code> does not. Check that{' '}
                <Code>ENVCLIENT_CLIENT_ID</Code> and{' '}
                <Code>ENVCLIENT_CLIENT_SECRET</Code> are set on the server. The
                deploy tokens page shows when each token was last used.
            </>
        ),
    },
    {
        problem: 'This deploy token may not be used from this address.',
        answer: (
            <>
                The server&apos;s IP address is not on an allow list. Both the
                environment&apos;s list and the token&apos;s own list must allow
                it. Change the token&apos;s list on the deploy tokens page, or
                the environment&apos;s list under the environment&apos;s
                settings. Every refusal is in the audit trail.
            </>
        ),
    },
    {
        problem: '403 when pushing with a deploy token',
        answer: (
            <>
                Deploy tokens are read only unless &ldquo;also allow this token
                to push variables&rdquo; was ticked when it was created. Create
                a new token with push access, or push with a personal login.
            </>
        ),
    },
    {
        problem: '--publish needs a personal login',
        answer: (
            <>
                A deploy token can push but never publish a release. Push
                without <Code>--publish</Code> and publish from the portal, or
                run <Code>envclient login</Code>.
            </>
        ),
    },
    {
        problem: 'Invalid scope(s) provided.',
        answer: (
            <>
                <Code>ENVCLIENT_SCOPES</Code> asks for less than the command
                needs. Unset it so the default applies.
            </>
        ),
    },
    {
        problem: 'a deploy token needs ENVCLIENT_SERVER set next to it',
        answer: (
            <>
                Set <Code>ENVCLIENT_SERVER</Code> in the same place as the
                client ID and secret. With a deploy token the server always
                comes from there, and envclient refuses when{' '}
                <Code>envclient.json</Code> names a different one.
            </>
        ),
    },
    {
        problem: 'not logged in, or your session expired',
        answer: (
            <>
                Run <Code>envclient login</Code>. A login lasts a limited number
                of days, set by the server.
            </>
        ),
    },
    {
        problem: 'no envclient.json found',
        answer: (
            <>
                Run the command inside a linked project, or run{' '}
                <Code>envclient init</Code> first. Deploy tokens do not need
                this file.
            </>
        ),
    },
    {
        problem: 'this vault was sealed with a different key',
        answer: (
            <>
                The deploy token was rotated, or{' '}
                <Code>ENVCLIENT_VAULT_KEY</Code> changed. Run{' '}
                <Code>envclient seal</Code> again with the current key.
            </>
        ),
    },
];

function CodeBlock({ children }: { children: string }) {
    return (
        <div className="rounded-md bg-muted">
            <div className="flex justify-end px-2 pt-2">
                <CopyButton
                    value={children}
                    label="Copy"
                    variant="ghost"
                    size="sm"
                />
            </div>
            <pre className="overflow-x-auto px-4 pb-4 font-mono text-xs leading-relaxed">
                {children}
            </pre>
        </div>
    );
}

function Section({
    id,
    title,
    children,
}: {
    id: string;
    title: string;
    children: ReactNode;
}) {
    return (
        <section id={id} className="scroll-mt-24 space-y-4">
            <h2 className="text-xl font-semibold">{title}</h2>
            {children}
        </section>
    );
}

function Prose({ children }: { children: ReactNode }) {
    return <p className="text-muted-foreground">{children}</p>;
}

function DefinitionTable({ rows }: { rows: Flag[] }) {
    return (
        <div className="overflow-x-auto rounded-lg border">
            <table className="w-full text-sm">
                <tbody>
                    {rows.map((row) => (
                        <tr key={row.name} className="border-b last:border-0">
                            <td className="w-1/3 p-3 align-top whitespace-nowrap">
                                <Code className="text-xs">{row.name}</Code>
                            </td>
                            <td className="p-3 text-muted-foreground">
                                {row.description}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

/**
 * How far below the top of the viewport a section heading has to scroll
 * before it counts as the one being read: just under the sticky header.
 */
const ACTIVE_SECTION_OFFSET = 120;

/**
 * Track which section is being read, for the table of contents.
 *
 * The active section is the last one whose top has passed just under the
 * sticky header. At the very bottom of the page the last section wins, even
 * when it is too short to ever reach that line.
 */
function useActiveSection(ids: string[]): string {
    const [active, setActive] = useState(ids[0]);

    useEffect(() => {
        const update = () => {
            const atBottom =
                window.innerHeight + window.scrollY >=
                document.documentElement.scrollHeight - 2;

            if (atBottom) {
                setActive(ids[ids.length - 1]);

                return;
            }

            let current = ids[0];

            for (const id of ids) {
                const element = document.getElementById(id);

                if (
                    element &&
                    element.getBoundingClientRect().top <= ACTIVE_SECTION_OFFSET
                ) {
                    current = id;
                }
            }

            setActive(current);
        };

        update();
        window.addEventListener('scroll', update, { passive: true });
        window.addEventListener('resize', update);

        return () => {
            window.removeEventListener('scroll', update);
            window.removeEventListener('resize', update);
        };
    }, [ids]);

    return active;
}

const sectionIds = sections.map((section) => section.id);

export default function CliDocumentation({ server }: Props) {
    const activeSection = useActiveSection(sectionIds);

    return (
        <>
            <Head title="CLI documentation" />

            <div className="flex flex-col gap-10">
                <header className="space-y-4">
                    <p className="text-sm font-medium tracking-wide text-muted-foreground uppercase">
                        Documentation
                    </p>
                    <h1 className="text-4xl font-semibold tracking-tight">
                        envclient
                    </h1>
                    <p className="max-w-2xl text-lg text-muted-foreground">
                        The command line client for Envserver. It pulls
                        environment variables into a .env file or straight into
                        a process, on your laptop, in CI and on deploy servers.
                    </p>
                </header>

                <div className="grid gap-10 lg:grid-cols-[12rem_1fr]">
                    <nav
                        aria-label="On this page"
                        className="lg:sticky lg:top-24 lg:self-start"
                    >
                        <ul className="flex flex-wrap gap-x-4 gap-y-2 text-sm lg:flex-col">
                            {sections.map((section) => (
                                <li key={section.id}>
                                    <a
                                        href={`#${section.id}`}
                                        aria-current={
                                            activeSection === section.id
                                                ? 'location'
                                                : undefined
                                        }
                                        className={cn(
                                            'transition-colors hover:text-foreground',
                                            activeSection === section.id
                                                ? 'font-medium text-foreground'
                                                : 'text-muted-foreground',
                                        )}
                                    >
                                        {section.title}
                                    </a>
                                </li>
                            ))}
                        </ul>
                    </nav>

                    <div className="min-w-0 space-y-14">
                        <Section id="install" title="Install">
                            <Prose>
                                The install script detects your OS and
                                architecture and installs to{' '}
                                <Code>/usr/local/bin</Code>, using{' '}
                                <Code>sudo</Code> when it needs to. When{' '}
                                <Code>cosign</Code> is installed it also checks
                                the release signature.
                            </Prose>
                            <CodeBlock>{`curl -fsSL ${INSTALL_SCRIPT} | sh`}</CodeBlock>
                            <Prose>
                                To install somewhere else, set{' '}
                                <Code>INSTALL_DIR</Code>. Set{' '}
                                <Code>ENVCLIENT_REQUIRE_SIGNATURE=1</Code> to
                                refuse an install that cannot be verified.
                            </Prose>
                            <CodeBlock>{`curl -fsSL ${INSTALL_SCRIPT} \\\n    | INSTALL_DIR=$HOME/.local/bin sh`}</CodeBlock>
                            <Prose>Or build it with Go:</Prose>
                            <CodeBlock>
                                {
                                    'go install github.com/I-247/envserver/cli/cmd/envclient@latest'
                                }
                            </CodeBlock>
                        </Section>

                        <Section id="link" title="Link a project">
                            <Prose>
                                Run this once per repository and commit the
                                resulting <Code>envclient.json</Code>. It names
                                the server, team, project and environment, and
                                holds no secrets.
                            </Prose>
                            <CodeBlock>{`envclient init --server ${server} \\\n    --team acme --project webshop --environment development`}</CodeBlock>
                            <CodeBlock>{`{\n    "server": "${server}",\n    "team": "acme",\n    "project": "webshop",\n    "environment": "development"\n}`}</CodeBlock>
                            <Prose>
                                Then log in. You get a code in the terminal and
                                approve it in the browser. The token is stored
                                in <Code>credentials.json</Code> in your user
                                config directory.
                            </Prose>
                            <CodeBlock>{'envclient login'}</CodeBlock>
                        </Section>

                        <Section id="day-to-day" title="Day to day">
                            <CodeBlock>
                                {[
                                    'envclient pull                  # show what would change, then ask',
                                    'envclient pull --constructive   # also add keys you do not have yet',
                                    'envclient pull --prune          # also delete keys the release no longer has',
                                    'envclient pull --dry-run        # only look',
                                    'envclient diff                  # compare your .env with the latest release',
                                    'envclient push -m "..."         # send your local values back',
                                    'envclient history               # release history for this environment',
                                    'envclient list                  # projects and environments you can reach',
                                ].join('\n')}
                            </CodeBlock>
                            <Prose>
                                <Code>pull</Code> never removes a key that
                                exists only in your file, and never adds one you
                                did not ask for. Your machine-specific entries
                                stay yours unless you pass <Code>--prune</Code>.
                            </Prose>
                        </Section>

                        <Section id="deploy-server" title="On a deploy server">
                            <Prose>
                                Create a deploy token on the environment&apos;s
                                deploy tokens page in the portal. A token
                                belongs to one environment, so nothing in the
                                deploy script names one. Set its credentials on
                                the server:
                            </Prose>
                            <CodeBlock>{`export ENVCLIENT_SERVER=${server}\nexport ENVCLIENT_CLIENT_ID=...\nexport ENVCLIENT_CLIENT_SECRET=...\n\nenvclient pull --constructive --force --out .env`}</CodeBlock>
                            <Prose>
                                <Code>--force</Code> is required: a deploy
                                server has no terminal to confirm at, so a pull
                                without it stops with an error.{' '}
                                <Code>--constructive</Code> lets the first
                                deploy create the file from nothing.
                            </Prose>
                            <Prose>
                                On a server you do not script, put the same
                                three lines in a <Code>.envclientrc</Code> file
                                next to <Code>envclient.json</Code>. A real
                                export wins over the file. Never commit it.
                            </Prose>
                            <Prose>
                                To skip the file entirely, hand the variables
                                straight to a process. Nothing is written to
                                disk:
                            </Prose>
                            <CodeBlock>
                                {'envclient run -- php artisan migrate --force'}
                            </CodeBlock>
                            <Prose>
                                A few things to know about deploy tokens:
                            </Prose>
                            <ul className="list-disc space-y-2 pl-5 text-muted-foreground">
                                <li>
                                    They are read only unless push was allowed
                                    when the token was created.
                                </li>
                                <li>
                                    A token can be limited to IP addresses, and
                                    that list can be changed later without a new
                                    secret. The environment&apos;s own list
                                    applies as well.
                                </li>
                                <li>
                                    Only pulls with a deploy token count as
                                    deploys in the portal. A pull with a
                                    personal login does not.
                                </li>
                            </ul>
                        </Section>

                        <Section id="ci" title="In CI">
                            <Prose>
                                <Code>envclient check</Code> exits 0 when your
                                file has every key the release has, and 2 when
                                it does not, so a pipeline can stop before a
                                deploy that would miss a key. Keys that only
                                exist in your file are reported but do not fail
                                the check, unless you pass <Code>--strict</Code>
                                .
                            </Prose>
                            <CodeBlock>{`# .github/workflows/env.yml\n- name: Check the environment file\n  env:\n    ENVCLIENT_SERVER: ${server}\n    ENVCLIENT_CLIENT_ID: \${{ secrets.ENVCLIENT_CLIENT_ID }}\n    ENVCLIENT_CLIENT_SECRET: \${{ secrets.ENVCLIENT_CLIENT_SECRET }}\n  run: envclient check --file .env.example`}</CodeBlock>
                            <CodeBlock>{`# .gitlab-ci.yml\ncheck:env:\n  script:\n    - envclient check --file .env.example`}</CodeBlock>
                        </Section>

                        <Section id="vault" title="Encrypted locally">
                            <Prose>
                                <Code>envclient seal</Code> fetches the release
                                once and stores it as{' '}
                                <Code>.env.envclient</Code>, encrypted with
                                AES-256-GCM. <Code>envclient run</Code> then
                                reads that file instead of the server: no
                                network call and no plaintext on disk.
                            </Prose>
                            <CodeBlock>
                                {
                                    'envclient seal\nenvclient run -- php artisan migrate --force'
                                }
                            </CodeBlock>
                            <Prose>
                                The key comes from the deploy token, so rotating
                                the token locks the old file; run{' '}
                                <Code>seal</Code> again. Without a deploy token,
                                pick your own key:
                            </Prose>
                            <CodeBlock>
                                {
                                    'export ENVCLIENT_VAULT_KEY=$(openssl rand -hex 32)'
                                }
                            </CodeBlock>
                            <Prose>
                                Keep <Code>.env.envclient</Code> out of version
                                control. <Code>envclient unseal</Code> shows the
                                contents when you need to look.
                            </Prose>
                        </Section>

                        <Section id="updating" title="Updating">
                            <CodeBlock>
                                {[
                                    'envclient update          # show and confirm before installing',
                                    'envclient update --check  # only report whether a newer release exists',
                                    'envclient update --force  # skip the confirmation',
                                ].join('\n')}
                            </CodeBlock>
                            <Prose>
                                An update is only installed when its checksums
                                carry a valid Sigstore signature from the
                                release workflow. It never installs an older
                                version.
                            </Prose>
                        </Section>

                        <Section id="commands" title="Commands">
                            <Prose>
                                Every command also takes <Code>--no-color</Code>{' '}
                                and <Code>--help</Code>.
                            </Prose>
                            <div className="space-y-8">
                                {commands.map((command) => (
                                    <div
                                        key={command.usage}
                                        className="space-y-3"
                                        data-test="cli-command"
                                    >
                                        <div>
                                            <h3 className="font-mono text-sm font-medium">
                                                {command.usage}
                                            </h3>
                                            <p className="mt-1 text-sm text-muted-foreground">
                                                {command.description}
                                            </p>
                                        </div>
                                        {command.flags.length > 0 ? (
                                            <DefinitionTable
                                                rows={command.flags}
                                            />
                                        ) : null}
                                    </div>
                                ))}
                            </div>
                        </Section>

                        <Section
                            id="environment-variables"
                            title="Environment variables"
                        >
                            <Prose>
                                The <Code>ENVCLIENT_SERVER</Code>,{' '}
                                <Code>ENVCLIENT_CLIENT_ID</Code>,{' '}
                                <Code>ENVCLIENT_CLIENT_SECRET</Code> and{' '}
                                <Code>ENVCLIENT_SCOPES</Code> variables can also
                                come from <Code>.envclientrc</Code> or{' '}
                                <Code>.env</Code> in the current directory. The
                                others must be exported.
                            </Prose>
                            <DefinitionTable rows={environmentVariables} />
                        </Section>

                        <Section id="exit-codes" title="Exit codes">
                            <DefinitionTable
                                rows={[
                                    {
                                        name: '0',
                                        description: 'The command succeeded.',
                                    },
                                    {
                                        name: '1',
                                        description:
                                            'The command could not run: no network, no token, no release, or another error.',
                                    },
                                    {
                                        name: '2',
                                        description:
                                            'envclient check only: your file does not match the release.',
                                    },
                                ]}
                            />
                        </Section>

                        <Section id="troubleshooting" title="Troubleshooting">
                            <div className="space-y-6">
                                {troubleshooting.map((item) => (
                                    <div
                                        key={item.problem}
                                        className="space-y-2"
                                    >
                                        <h3 className="font-mono text-sm font-medium">
                                            {item.problem}
                                        </h3>
                                        <p className="text-sm text-muted-foreground">
                                            {item.answer}
                                        </p>
                                    </div>
                                ))}
                            </div>
                        </Section>
                    </div>
                </div>
            </div>
        </>
    );
}
