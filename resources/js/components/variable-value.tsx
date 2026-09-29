import { Loader2 } from 'lucide-react';
import { useState } from 'react';
import Code from '@/components/code';
import CopyButton from '@/components/copy-button';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { csrfToken } from '@/lib/csrf';

type Props = {
    variableKey: string;
    revealUrl: string;
    confirmUrl: string;
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
 *
 * The server answers 423 until the password was confirmed in the last few
 * minutes (SecretAccessWindow). The dialog then asks for it in place and tries
 * again, so one confirmation covers the next reveals without a page reload.
 */
export default function VariableValue({
    variableKey,
    revealUrl,
    confirmUrl,
    canReveal,
}: Props) {
    const [open, setOpen] = useState(false);
    const [value, setValue] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);
    const [failed, setFailed] = useState(false);
    const [needsPassword, setNeedsPassword] = useState(false);
    const [password, setPassword] = useState('');
    const [passwordError, setPasswordError] = useState<string | undefined>();

    if (!canReveal) {
        return <Code className="text-muted-foreground">••••••••</Code>;
    }

    const reveal = async () => {
        setLoading(true);
        setFailed(false);

        try {
            const response = await fetch(revealUrl, {
                headers: { Accept: 'application/json' },
            });

            if (response.status === 423) {
                setNeedsPassword(true);

                return;
            }

            if (!response.ok) {
                throw new Error(String(response.status));
            }

            setNeedsPassword(false);
            setValue((await response.json()).value);
        } catch {
            setFailed(true);
        } finally {
            setLoading(false);
        }
    };

    const confirm = async (event: React.FormEvent) => {
        event.preventDefault();
        setLoading(true);
        setPasswordError(undefined);

        try {
            const response = await fetch(confirmUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({ password }),
            });

            if (!response.ok) {
                const body = await response.json().catch(() => null);

                setPasswordError(
                    body?.errors?.password?.[0] ??
                        (response.status === 429
                            ? 'Too many attempts. Try again in a minute.'
                            : 'Could not confirm your password.'),
                );
                setLoading(false);

                return;
            }

            setPassword('');
            await reveal();
        } catch {
            setPasswordError('Could not reach the server.');
            setLoading(false);
        }
    };

    const toggle = async (next: boolean) => {
        setOpen(next);

        if (!next) {
            setValue(null);
            setFailed(false);
            setNeedsPassword(false);
            setPassword('');
            setPasswordError(undefined);

            return;
        }

        await reveal();
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
                        {needsPassword ? (
                            <form
                                onSubmit={confirm}
                                className="grid gap-2"
                                data-test="reveal-password-form"
                            >
                                <Label
                                    htmlFor={`reveal-password-${variableKey}`}
                                >
                                    Confirm your password to reveal secrets
                                </Label>
                                <PasswordInput
                                    id={`reveal-password-${variableKey}`}
                                    name="password"
                                    value={password}
                                    onChange={(event) =>
                                        setPassword(event.target.value)
                                    }
                                    placeholder="Your password"
                                    autoComplete="current-password"
                                    autoFocus
                                    data-test="reveal-password"
                                />
                                <InputError message={passwordError} />
                                <Button
                                    type="submit"
                                    disabled={loading || password === ''}
                                    className="justify-self-start"
                                >
                                    Reveal
                                </Button>
                            </form>
                        ) : loading ? (
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

                    {!loading && !failed && !needsPassword ? (
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
