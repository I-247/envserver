import { Loader2 } from 'lucide-react';
import { useState } from 'react';
import Code from '@/components/code';
import CopyButton from '@/components/copy-button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';

type Props = {
    variableKey: string;
    revealUrl: string;
    canReveal: boolean;
};

/**
 * Shows a masked value until someone asks for it, then in a modal.
 *
 * The plaintext is never in the page payload: it is fetched on demand, so a
 * dashboard left open on a shared screen gives nothing away, and the server
 * gets a chance to record who looked. Putting it in a modal keeps it out of
 * the table entirely: a long secret gets room to wrap instead of being
 * truncated, only one value is ever on screen, and closing the dialog throws
 * the plaintext away again.
 */
export default function VariableValue({
    variableKey,
    revealUrl,
    canReveal,
}: Props) {
    const [open, setOpen] = useState(false);
    const [value, setValue] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);
    const [failed, setFailed] = useState(false);

    if (!canReveal) {
        return <Code className="text-muted-foreground">••••••••</Code>;
    }

    const toggle = async (next: boolean) => {
        setOpen(next);

        if (!next) {
            setValue(null);
            setFailed(false);

            return;
        }

        setLoading(true);
        setFailed(false);

        try {
            const response = await fetch(revealUrl, {
                headers: { Accept: 'application/json' },
            });

            if (!response.ok) {
                throw new Error(String(response.status));
            }

            setValue((await response.json()).value);
        } catch {
            setFailed(true);
        } finally {
            setLoading(false);
        }
    };

    return (
        <Dialog open={open} onOpenChange={toggle}>
            <DialogTrigger asChild>
                <button
                    type="button"
                    className="cursor-pointer rounded text-left hover:opacity-80 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                    aria-label={`Show value of ${variableKey}`}
                    data-test="reveal-value"
                >
                    <Code>••••••••</Code>
                </button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{variableKey}</DialogTitle>
                    <DialogDescription>
                        This value was fetched just now and is recorded in the
                        audit trail. It is discarded when you close this dialog.
                    </DialogDescription>
                </DialogHeader>
                <div className="relative rounded-md border bg-muted/40">
                    <div className="max-h-64 overflow-auto p-3 pr-12 text-sm">
                        {loading ? (
                            <div
                                className="flex items-center gap-2 text-muted-foreground"
                                data-test="reveal-loading"
                            >
                                <Loader2 className="size-4 animate-spin" />
                                Loading value
                            </div>
                        ) : failed ? (
                            <p className="text-destructive">
                                Could not load this value.
                            </p>
                        ) : (
                            <pre
                                className="break-all whitespace-pre-wrap"
                                data-test="revealed-value"
                            >
                                {value}
                            </pre>
                        )}
                    </div>

                    {!loading && !failed ? (
                        <CopyButton
                            value={value ?? ''}
                            variant="ghost"
                            size="icon"
                            className="absolute top-1.5 right-1.5 size-8 text-muted-foreground hover:text-foreground"
                        />
                    ) : null}
                </div>
            </DialogContent>
        </Dialog>
    );
}
