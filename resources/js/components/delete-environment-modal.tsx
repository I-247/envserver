import { Form } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import { useState } from 'react';
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
import { destroy } from '@/routes/environments';
import type { EnvironmentSummary } from '@/types';

type Props = PropsWithChildren<{
    teamSlug: string;
    projectSlug: string;
    environment: EnvironmentSummary;
}>;

/**
 * Deleting an environment throws away its release history and its deploy
 * tokens, neither of which can be recreated, so the name has to be typed out
 * before the button unlocks.
 */
export default function DeleteEnvironmentModal({
    teamSlug,
    projectSlug,
    environment,
    children,
}: Props) {
    const [open, setOpen] = useState(false);
    const [confirmationName, setConfirmationName] = useState('');

    const canDelete = confirmationName === environment.name;

    const handleOpenChange = (nextOpen: boolean) => {
        setOpen(nextOpen);

        if (!nextOpen) {
            setConfirmationName('');
        }
    };

    return (
        <Dialog open={open} onOpenChange={handleOpenChange}>
            <DialogTrigger asChild>{children}</DialogTrigger>
            <DialogContent>
                <Form
                    key={String(open)}
                    {...destroy.form.delete([
                        teamSlug,
                        projectSlug,
                        environment.slug,
                    ])}
                    className="space-y-6"
                    onSuccess={() => handleOpenChange(false)}
                >
                    {({ processing }) => (
                        <>
                            <DialogHeader>
                                <DialogTitle>Are you sure?</DialogTitle>
                                <DialogDescription>
                                    This action cannot be undone. Deleting{' '}
                                    <strong>
                                        &quot;{environment.name}&quot;
                                    </strong>{' '}
                                    permanently removes its release history and
                                    its deploy tokens, and any server still
                                    pulling from it will stop receiving
                                    variables. Variables shared with another
                                    environment stay; the ones left without any
                                    environment are removed, unless a release
                                    still pins them.
                                </DialogDescription>
                            </DialogHeader>

                            <div className="space-y-4 py-4">
                                <div className="grid gap-2">
                                    <Label htmlFor="delete-environment-name">
                                        Type{' '}
                                        <strong>
                                            &quot;{environment.name}&quot;
                                        </strong>{' '}
                                        to confirm
                                    </Label>
                                    <Input
                                        id="delete-environment-name"
                                        data-test="delete-environment-name"
                                        value={confirmationName}
                                        onChange={(event) =>
                                            setConfirmationName(
                                                event.target.value,
                                            )
                                        }
                                        placeholder="Enter environment name"
                                        autoComplete="off"
                                    />
                                </div>
                            </div>

                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button variant="secondary">Cancel</Button>
                                </DialogClose>

                                <Button
                                    variant="destructive"
                                    type="submit"
                                    data-test="delete-environment-confirm"
                                    disabled={!canDelete || processing}
                                >
                                    Delete environment
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
