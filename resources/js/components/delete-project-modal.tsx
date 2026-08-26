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
import { destroy } from '@/routes/projects';

type Props = PropsWithChildren<{
    teamSlug: string;
    project: {
        name: string;
        slug: string;
    };
}>;

/**
 * Deleting a project cascades to its environments, releases and deploy
 * tokens, and takes the variables it orphans with it, so the name has to be
 * typed out before the button unlocks.
 */
export default function DeleteProjectModal({
    teamSlug,
    project,
    children,
}: Props) {
    const [open, setOpen] = useState(false);
    const [confirmationName, setConfirmationName] = useState('');

    const canDeleteProject = confirmationName === project.name;

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
                    {...destroy.form.delete([teamSlug, project.slug])}
                    className="space-y-6"
                    onSuccess={() => handleOpenChange(false)}
                >
                    {({ processing }) => (
                        <>
                            <DialogHeader>
                                <DialogTitle>Are you sure?</DialogTitle>
                                <DialogDescription>
                                    This action cannot be undone. Deleting{' '}
                                    <strong>&quot;{project.name}&quot;</strong>{' '}
                                    permanently removes its environments,
                                    releases and deploy tokens. Variables left
                                    without any environment are removed too,
                                    unless a release still pins them. Any server
                                    still pulling this project will stop
                                    receiving variables.
                                </DialogDescription>
                            </DialogHeader>

                            <div className="space-y-4 py-4">
                                <div className="grid gap-2">
                                    <Label htmlFor="delete-project-name">
                                        Type{' '}
                                        <strong>
                                            &quot;{project.name}&quot;
                                        </strong>{' '}
                                        to confirm
                                    </Label>
                                    <Input
                                        id="delete-project-name"
                                        data-test="delete-project-name"
                                        value={confirmationName}
                                        onChange={(event) =>
                                            setConfirmationName(
                                                event.target.value,
                                            )
                                        }
                                        placeholder="Enter project name"
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
                                    data-test="delete-project-confirm"
                                    disabled={!canDeleteProject || processing}
                                >
                                    Delete project
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
