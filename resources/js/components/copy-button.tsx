import { Check, Copy } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

type Props = {
    value: string;
    label?: string;
    className?: string;
    variant?: React.ComponentProps<typeof Button>['variant'];
    size?: React.ComponentProps<typeof Button>['size'];
};

/**
 * Copy a value to the clipboard, falling back to a hidden textarea.
 *
 * navigator.clipboard only exists in a secure context, and a self hosted Kluis
 * on plain http is entirely plausible. On exactly the page where copying is
 * the whole point, a silent no-op would be the worst possible outcome.
 */
async function writeToClipboard(value: string): Promise<boolean> {
    if (navigator.clipboard && window.isSecureContext) {
        try {
            await navigator.clipboard.writeText(value);

            return true;
        } catch {
            // Fall through to the legacy path below.
        }
    }

    const textarea = document.createElement('textarea');
    textarea.value = value;
    textarea.setAttribute('readonly', '');
    textarea.style.position = 'fixed';
    textarea.style.opacity = '0';
    document.body.appendChild(textarea);
    textarea.select();

    try {
        return document.execCommand('copy');
    } catch {
        return false;
    } finally {
        document.body.removeChild(textarea);
    }
}

export default function CopyButton({
    value,
    label,
    className,
    variant = 'secondary',
    size,
}: Props) {
    const [state, setState] = useState<'idle' | 'copied' | 'failed'>('idle');

    useEffect(() => {
        if (state === 'idle') {
            return;
        }

        const timer = window.setTimeout(() => setState('idle'), 2000);

        return () => window.clearTimeout(timer);
    }, [state]);

    const copy = async () => {
        setState((await writeToClipboard(value)) ? 'copied' : 'failed');
    };

    const text =
        state === 'copied'
            ? 'Copied'
            : state === 'failed'
              ? 'Press ⌘C'
              : (label ?? 'Copy');

    return (
        <Button
            type="button"
            variant={variant}
            size={size ?? (label ? 'default' : 'icon')}
            onClick={copy}
            className={cn(className)}
            aria-label={label ? undefined : 'Copy to clipboard'}
            data-test="copy-button"
        >
            {state === 'copied' ? <Check /> : <Copy />}
            {label || state !== 'idle' ? (
                <span aria-live="polite">{text}</span>
            ) : null}
        </Button>
    );
}
