import { Form, router } from '@inertiajs/react';
import { Search, Share2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import Code from '@/components/code';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import environments from '@/routes/environments';
import type { ShareableVariable } from '@/types';

type Props = {
    args: [string, string, string];
    shareable?: ShareableVariable[];
};

/**
 * Borrows a variable that another project in the team already owns.
 *
 * The list is an optional Inertia prop, so it is fetched the first time the
 * dialog opens rather than on every page load: most visits to an environment
 * never share anything.
 */
export default function ShareVariableModal({ args, shareable }: Props) {
    const [open, setOpen] = useState(false);
    const [selected, setSelected] = useState<ShareableVariable | null>(null);
    const [query, setQuery] = useState('');

    const openDialog = (next: boolean) => {
        setOpen(next);

        if (next && shareable === undefined) {
            router.reload({ only: ['shareable'] });
        }

        if (!next) {
            setSelected(null);
            setQuery('');
        }
    };

    const matches = useMemo(() => {
        const needle = query.trim().toLowerCase();

        if (needle === '') {
            return shareable ?? [];
        }

        return (shareable ?? []).filter(
            (entry) =>
                entry.key.toLowerCase().includes(needle) ||
                entry.project.toLowerCase().includes(needle),
        );
    }, [shareable, query]);

    return (
        <Dialog open={open} onOpenChange={openDialog}>
            <DialogTrigger asChild>
                <Button variant="secondary" data-test="share-variable">
                    <Share2 /> Share from project
                </Button>
            </DialogTrigger>

            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        Use a variable from another project
                    </DialogTitle>
                    <DialogDescription>
                        The value is not copied. Both projects read the same
                        variable, so changing it once changes it for all of
                        them.
                    </DialogDescription>
                </DialogHeader>

                {shareable === undefined ? (
                    <div className="flex items-center justify-center gap-2 py-10 text-sm text-muted-foreground">
                        <Spinner /> Looking for variables…
                    </div>
                ) : shareable.length === 0 ? (
                    <p
                        className="rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground"
                        data-test="nothing-shareable"
                    >
                        The team's other projects have no variables to share
                        yet.
                    </p>
                ) : (
                    <Form
                        key={selected?.id ?? 'none'}
                        {...environments.variables.share.form(args)}
                        className="space-y-4"
                        onSuccess={() => openDialog(false)}
                    >
                        {({ errors, processing }) => (
                            <>
                                <div className="relative">
                                    <Search className="pointer-events-none absolute top-2.5 left-3 size-4 text-muted-foreground" />
                                    <Input
                                        value={query}
                                        onChange={(event) =>
                                            setQuery(event.target.value)
                                        }
                                        placeholder="Search by name or project"
                                        className="pl-9"
                                        aria-label="Search variables"
                                    />
                                </div>

                                <div className="max-h-64 space-y-1 overflow-y-auto rounded-lg border p-1">
                                    {matches.length === 0 ? (
                                        <p className="p-6 text-center text-sm text-muted-foreground">
                                            Nothing matches “{query}”.
                                        </p>
                                    ) : (
                                        matches.map((entry) => (
                                            <button
                                                key={entry.id}
                                                type="button"
                                                data-test="shareable-variable"
                                                onClick={() =>
                                                    setSelected(entry)
                                                }
                                                aria-pressed={
                                                    selected?.id === entry.id
                                                }
                                                className={`flex w-full cursor-pointer items-center justify-between gap-3 rounded-md p-2 text-left transition-colors ${
                                                    selected?.id === entry.id
                                                        ? 'bg-accent'
                                                        : 'hover:bg-accent/50'
                                                }`}
                                            >
                                                <span className="min-w-0">
                                                    <Code>{entry.key}</Code>
                                                    <span className="mt-0.5 block truncate text-xs text-muted-foreground">
                                                        {entry.project}
                                                    </span>
                                                </span>

                                                {entry.sharedWith > 1 ? (
                                                    <Badge variant="secondary">
                                                        <Share2 className="size-3" />
                                                        {entry.sharedWith}
                                                    </Badge>
                                                ) : null}
                                            </button>
                                        ))
                                    )}
                                </div>

                                <input
                                    type="hidden"
                                    name="variable_id"
                                    value={selected?.id ?? ''}
                                />
                                <InputError message={errors.variable_id} />

                                <div className="grid gap-2">
                                    <Label htmlFor="alias_key">
                                        Name in this environment
                                    </Label>
                                    <Input
                                        id="alias_key"
                                        name="alias_key"
                                        className="font-mono"
                                        placeholder={
                                            selected?.key ?? 'Leave empty'
                                        }
                                        autoComplete="off"
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        Optional. Leave empty to keep{' '}
                                        {selected ? (
                                            <Code>{selected.key}</Code>
                                        ) : (
                                            'the original name'
                                        )}
                                        .
                                    </p>
                                    <InputError message={errors.alias_key} />
                                </div>

                                <DialogFooter className="gap-2">
                                    <DialogClose asChild>
                                        <Button variant="secondary">
                                            Cancel
                                        </Button>
                                    </DialogClose>
                                    <Button
                                        type="submit"
                                        disabled={processing || !selected}
                                        data-test="share-variable-submit"
                                    >
                                        Share
                                    </Button>
                                </DialogFooter>
                            </>
                        )}
                    </Form>
                )}
            </DialogContent>
        </Dialog>
    );
}
