import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import environments from '@/routes/environments';
import type { EnvironmentVariable } from '@/types';

type Props = {
    args: [string, string, string];
    environmentName: string;
    variable: EnvironmentVariable | null;
    onOpenChange: (open: boolean) => void;
};

/**
 * Confirms removing a variable from one environment.
 *
 * Nothing is destroyed here even for the project that owns the variable: the
 * other environments keep it and one of them inherits it. The copy spells that
 * out, because "delete" reads as final and this is not. The key still has to be
 * typed out, because the rows sit close together and the wrong secret
 * disappearing from a running environment is expensive to notice.
 *
 * The dialog is controlled by the page and rendered once, with the variable
 * passed in, rather than one dialog per table row.
 */
export default function DeleteVariableModal({
    args,
    environmentName,
    variable,
    onOpenChange,
}: Props) {
    return (
        <Dialog open={variable !== null} onOpenChange={onOpenChange}>
            <DialogContent>
                {variable ? (
                    <DeleteVariableConfirmation
                        key={variable.id}
                        args={args}
                        environmentName={environmentName}
                        variable={variable}
                        onOpenChange={onOpenChange}
                    />
                ) : null}
            </DialogContent>
        </Dialog>
    );
}

/**
 * The body lives in its own component keyed by the variable, so opening the
 * dialog for another row remounts it and the typed key starts out empty
 * instead of carrying over from the previous variable.
 */
function DeleteVariableConfirmation({
    args,
    environmentName,
    variable,
    onOpenChange,
}: Omit<Props, 'variable'> & { variable: EnvironmentVariable }) {
    const [processing, setProcessing] = useState(false);
    const [confirmationKey, setConfirmationKey] = useState('');

    const elsewhere = variable.sharedWith - 1;
    const canDelete = confirmationKey === variable.key;

    const detach = () => {
        if (!canDelete) {
            return;
        }

        router.delete(
            environments.variables.destroy.url([...args, variable.id]),
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: () => onOpenChange(false),
            },
        );
    };

    return (
        <>
            <DialogHeader>
                <DialogTitle>Remove this variable?</DialogTitle>
                <DialogDescription>
                    <strong>{variable.key}</strong> is removed from{' '}
                    <strong>{environmentName}</strong>, so servers pulling from
                    this environment stop receiving it after the next release.{' '}
                    {elsewhere > 0
                        ? `It stays in the ${elsewhere} other environment${elsewhere === 1 ? '' : 's'} using it.`
                        : 'Earlier releases keep the value they were published with.'}
                </DialogDescription>
            </DialogHeader>

            <div className="grid gap-2 py-4">
                <Label htmlFor="delete-variable-key">
                    Type <strong>{variable.key}</strong> to confirm
                </Label>
                <Input
                    id="delete-variable-key"
                    data-test="delete-variable-key"
                    value={confirmationKey}
                    onChange={(event) => setConfirmationKey(event.target.value)}
                    onKeyDown={(event) => {
                        if (event.key === 'Enter' && canDelete) {
                            event.preventDefault();
                            detach();
                        }
                    }}
                    placeholder="Enter the variable key"
                    autoComplete="off"
                    autoCapitalize="off"
                    spellCheck={false}
                    className="font-mono"
                />
            </div>

            <DialogFooter className="gap-2">
                <Button
                    variant="secondary"
                    onClick={() => onOpenChange(false)}
                    disabled={processing}
                >
                    Cancel
                </Button>

                <Button
                    variant="destructive"
                    data-test="delete-variable-confirm"
                    onClick={detach}
                    disabled={!canDelete || processing}
                >
                    Remove variable
                </Button>
            </DialogFooter>
        </>
    );
}
