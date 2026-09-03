import { cn } from '@/lib/utils';

type Props = React.ComponentProps<'code'>;

/**
 * A literal value: a variable name, a secret, a command, an identifier.
 *
 * Now that the whole interface is set in a monospace face, `font-mono` no
 * longer separates these from ordinary prose. The muted chip does that job
 * instead, so a key or a command still reads as something to be copied
 * verbatim rather than something to be read.
 */
export default function Code({ className, ...props }: Props) {
    return (
        <code
            className={cn('rounded bg-muted px-1.5 py-0.5 text-sm', className)}
            {...props}
        />
    );
}
