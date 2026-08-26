import { router } from '@inertiajs/react';
import { ArrowLeft, FileUp, Loader2 } from 'lucide-react';
import { useState } from 'react';
import Code from '@/components/code';
import InputError from '@/components/input-error';
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
import { Textarea } from '@/components/ui/textarea';
import environments from '@/routes/environments';

type Props = {
    args: [string, string, string];
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
};

type Preview = {
    adding: string[];
    conflicting: string[];
};

type Strategy = 'overwrite' | 'keep';

/**
 * Reads the CSRF token Laravel put in a cookie.
 *
 * The preview is a plain JSON call rather than an Inertia visit, so it has to
 * carry the token itself.
 */
function csrfToken(): string {
    const cookie = document.cookie
        .split('; ')
        .find((entry) => entry.startsWith('XSRF-TOKEN='));

    return cookie ? decodeURIComponent(cookie.split('=')[1]) : '';
}

/**
 * Pastes a .env file into an environment.
 *
 * Importing happens in two steps: the server first says which keys are new
 * and which ones the environment already holds, and only then is there a
 * choice to make about the ones that collide. Deciding blind is how a
 * carefully rotated production secret gets flattened by a stale local file.
 */
export default function ImportEnvModal({ args, open, onOpenChange }: Props) {
    const [internalOpen, setInternalOpen] = useState(false);
    const [contents, setContents] = useState('');
    const [preview, setPreview] = useState<Preview | null>(null);
    const [strategy, setStrategy] = useState<Strategy>('overwrite');
    const [error, setError] = useState<string | undefined>();
    const [processing, setProcessing] = useState(false);

    const controlled = onOpenChange !== undefined;
    const isOpen = controlled ? (open ?? false) : internalOpen;

    const reset = (next: boolean) => {
        if (controlled) {
            onOpenChange(next);
        } else {
            setInternalOpen(next);
        }

        if (!next) {
            setContents('');
            setPreview(null);
            setStrategy('overwrite');
            setError(undefined);
            setProcessing(false);
        }
    };

    const check = async () => {
        setProcessing(true);
        setError(undefined);

        try {
            const response = await fetch(
                environments.envFile.preview.url(args),
                {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-XSRF-TOKEN': csrfToken(),
                    },
                    body: JSON.stringify({ contents }),
                },
            );

            const body = await response.json();

            if (!response.ok) {
                setError(
                    body?.errors?.contents?.[0] ??
                        'Could not read this as a .env file.',
                );

                return;
            }

            setPreview(body);
        } catch {
            setError('Could not reach the server.');
        } finally {
            setProcessing(false);
        }
    };

    const submit = () => {
        setProcessing(true);

        router.post(
            environments.envFile.store.url(args),
            { contents, strategy },
            {
                onSuccess: () => reset(false),
                onError: (errors) => setError(errors.contents),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <Dialog open={isOpen} onOpenChange={reset}>
            {controlled ? null : (
                <DialogTrigger asChild>
                    <Button variant="secondary" data-test="import-env">
                        <FileUp /> Import .env
                    </Button>
                </DialogTrigger>
            )}

            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Import a .env file</DialogTitle>
                    <DialogDescription>
                        {preview === null
                            ? 'Paste the file below. Every value is encrypted the moment it arrives, and the ones already in the vault keep their history.'
                            : 'Nothing has been written yet. This is what the import would do.'}
                    </DialogDescription>
                </DialogHeader>

                {preview === null ? (
                    <div className="grid gap-2">
                        <Label htmlFor="env-contents">Contents</Label>
                        <Textarea
                            id="env-contents"
                            name="contents"
                            value={contents}
                            onChange={(event) =>
                                setContents(event.target.value)
                            }
                            className="max-h-80 min-h-56 font-mono text-xs"
                            placeholder={
                                'APP_ENV=production\nDB_PASSWORD="p$ssw0rd"'
                            }
                            spellCheck={false}
                            autoComplete="off"
                            data-test="import-env-contents"
                            autoFocus
                        />
                        <InputError message={error} />
                    </div>
                ) : (
                    <ImportPreview
                        preview={preview}
                        strategy={strategy}
                        onStrategyChange={setStrategy}
                        error={error}
                    />
                )}

                <DialogFooter className="gap-2">
                    {preview === null ? (
                        <>
                            <Button
                                variant="secondary"
                                onClick={() => reset(false)}
                            >
                                Cancel
                            </Button>
                            <Button
                                onClick={check}
                                disabled={processing || contents.trim() === ''}
                                data-test="import-env-check"
                            >
                                {processing ? (
                                    <Loader2 className="size-4 animate-spin" />
                                ) : null}
                                Continue
                            </Button>
                        </>
                    ) : (
                        <>
                            <Button
                                variant="secondary"
                                onClick={() => setPreview(null)}
                            >
                                <ArrowLeft /> Back
                            </Button>
                            <Button
                                onClick={submit}
                                disabled={processing}
                                data-test="import-env-submit"
                            >
                                {processing ? (
                                    <Loader2 className="size-4 animate-spin" />
                                ) : null}
                                Import
                            </Button>
                        </>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/**
 * What the import would touch, and the choice the conflicts call for.
 */
function ImportPreview({
    preview,
    strategy,
    onStrategyChange,
    error,
}: {
    preview: Preview;
    strategy: Strategy;
    onStrategyChange: (strategy: Strategy) => void;
    error?: string;
}) {
    return (
        <div className="space-y-4" data-test="import-env-preview">
            <KeyList
                title={`${preview.adding.length} new`}
                description="Added to this environment."
                keys={preview.adding}
            />

            {preview.conflicting.length > 0 ? (
                <>
                    <KeyList
                        title={`${preview.conflicting.length} already here`}
                        description="These names are already in the vault."
                        keys={preview.conflicting}
                    />

                    <fieldset className="space-y-2">
                        <legend className="mb-2 text-sm font-medium">
                            What should happen to those?
                        </legend>

                        <StrategyOption
                            value="overwrite"
                            selected={strategy}
                            onSelect={onStrategyChange}
                            label="Take the pasted value"
                            description="Stores a new version. The old value stays in the history and can be rolled back to."
                        />
                        <StrategyOption
                            value="keep"
                            selected={strategy}
                            onSelect={onStrategyChange}
                            label="Keep what the vault has"
                            description="Leaves these untouched and imports only the new names."
                        />
                    </fieldset>
                </>
            ) : null}

            <InputError message={error} />
        </div>
    );
}

function KeyList({
    title,
    description,
    keys,
}: {
    title: string;
    description: string;
    keys: string[];
}) {
    if (keys.length === 0) {
        return null;
    }

    return (
        <div className="space-y-2">
            <p className="text-sm font-medium">{title}</p>
            <p className="text-sm text-muted-foreground">{description}</p>
            <div className="flex max-h-32 flex-wrap gap-1 overflow-y-auto">
                {keys.map((key) => (
                    <Code key={key}>{key}</Code>
                ))}
            </div>
        </div>
    );
}

function StrategyOption({
    value,
    selected,
    onSelect,
    label,
    description,
}: {
    value: Strategy;
    selected: Strategy;
    onSelect: (strategy: Strategy) => void;
    label: string;
    description: string;
}) {
    return (
        <label
            className={`flex cursor-pointer gap-3 rounded-lg border p-3 ${
                selected === value ? 'border-primary bg-muted/40' : ''
            }`}
        >
            <input
                type="radio"
                name="strategy"
                value={value}
                checked={selected === value}
                onChange={() => onSelect(value)}
                className="mt-1"
                data-test={`import-env-strategy-${value}`}
            />
            <span className="space-y-1">
                <span className="block text-sm font-medium">{label}</span>
                <span className="block text-sm text-muted-foreground">
                    {description}
                </span>
            </span>
        </label>
    );
}
