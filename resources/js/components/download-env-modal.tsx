import { Download, Loader2 } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import environments from '@/routes/environments';

type Props = {
    args: [string, string, string];
    environmentName: string;
    variableCount: number;
};

/**
 * Reads the CSRF token Laravel put in a cookie.
 *
 * The download is a plain fetch rather than an Inertia visit, because the
 * answer is a file and not a page, so it has to carry the token itself.
 */
function csrfToken(): string {
    const cookie = document.cookie
        .split('; ')
        .find((entry) => entry.startsWith('XSRF-TOKEN='));

    return cookie ? decodeURIComponent(cookie.split('=')[1]) : '';
}

/**
 * Pull the server's filename out of the response, falling back to a sane one.
 */
function filenameFrom(disposition: string | null): string {
    const match = disposition?.match(/filename="([^"]+)"/);

    return match ? match[1] : 'kluis.env.txt';
}

/**
 * Hand the response body to the browser as a file.
 */
function save(contents: Blob, filename: string): void {
    const url = URL.createObjectURL(contents);
    const link = document.createElement('a');

    link.href = url;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);

    URL.revokeObjectURL(url);
}

/**
 * Downloads an environment as a .env file, behind the password.
 *
 * One click here takes every secret out of the vault at once, so the password
 * is asked for on every download rather than remembered: the point is not to
 * prove who is sitting there, it is to make the export a deliberate act.
 */
export default function DownloadEnvModal({
    args,
    environmentName,
    variableCount,
}: Props) {
    const [open, setOpen] = useState(false);
    const [password, setPassword] = useState('');
    const [error, setError] = useState<string | undefined>();
    const [processing, setProcessing] = useState(false);

    const reset = (next: boolean) => {
        setOpen(next);

        if (!next) {
            setPassword('');
            setError(undefined);
            setProcessing(false);
        }
    };

    const submit = async (event: React.FormEvent) => {
        event.preventDefault();
        setProcessing(true);
        setError(undefined);

        try {
            const response = await fetch(
                environments.envFile.download.url(args),
                {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'text/plain, application/json',
                        'X-XSRF-TOKEN': csrfToken(),
                    },
                    body: JSON.stringify({ password }),
                },
            );

            if (!response.ok) {
                const body = await response.json().catch(() => null);

                setError(
                    body?.errors?.password?.[0] ??
                        (response.status === 429
                            ? 'Too many attempts. Try again in a minute.'
                            : 'Could not download this environment.'),
                );

                return;
            }

            save(
                await response.blob(),
                filenameFrom(response.headers.get('Content-Disposition')),
            );

            reset(false);
        } catch {
            setError('Could not reach the server.');
        } finally {
            setProcessing(false);
        }
    };

    return (
        <Dialog open={open} onOpenChange={reset}>
            <DialogTrigger asChild>
                <Button variant="secondary" data-test="download-env">
                    <Download /> Download .env
                </Button>
            </DialogTrigger>

            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        Download {environmentName} as .env
                    </DialogTitle>
                    <DialogDescription>
                        This writes{' '}
                        {variableCount === 1
                            ? '1 value'
                            : `all ${variableCount} values`}{' '}
                        to a file in plaintext, and the download is recorded in
                        the audit trail. Enter your password to confirm.
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={submit} className="space-y-6">
                    <div className="grid gap-2">
                        <Label htmlFor="download-env-password">Password</Label>

                        <PasswordInput
                            id="download-env-password"
                            name="password"
                            value={password}
                            onChange={(event) =>
                                setPassword(event.target.value)
                            }
                            placeholder="Your password"
                            autoComplete="current-password"
                            data-test="download-env-password"
                            autoFocus
                        />

                        <InputError message={error} />
                    </div>

                    <DialogFooter className="gap-2">
                        <Button
                            type="button"
                            variant="secondary"
                            onClick={() => reset(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            disabled={processing || password === ''}
                            data-test="download-env-submit"
                        >
                            {processing ? (
                                <Loader2 className="size-4 animate-spin" />
                            ) : null}
                            Download
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
