import { Eye, EyeOff, Loader2 } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';

type Props = {
    revealUrl: string;
    canReveal: boolean;
};

/**
 * Shows a masked value until someone asks for it.
 *
 * The plaintext is never in the page payload: it is fetched on demand, so a
 * dashboard left open on a shared screen gives nothing away, and the server
 * gets a chance to record who looked.
 */
export default function VariableValue({ revealUrl, canReveal }: Props) {
    const [value, setValue] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);
    const [failed, setFailed] = useState(false);

    const toggle = async () => {
        if (value !== null) {
            setValue(null);

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

    if (!canReveal) {
        return (
            <span className="font-mono text-sm text-muted-foreground">
                ••••••••
            </span>
        );
    }

    return (
        <div className="flex items-center gap-2">
            <span className="max-w-md truncate font-mono text-sm">
                {failed ? 'Could not load' : (value ?? '••••••••')}
            </span>
            <Button
                type="button"
                variant="ghost"
                size="icon"
                onClick={toggle}
                aria-label={value === null ? 'Reveal value' : 'Hide value'}
                data-test="reveal-value"
            >
                {loading ? (
                    <Loader2 className="size-4 animate-spin" />
                ) : value === null ? (
                    <Eye className="size-4" />
                ) : (
                    <EyeOff className="size-4" />
                )}
            </Button>
        </div>
    );
}
