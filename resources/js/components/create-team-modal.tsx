import { Form } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import { useState } from 'react';
import InputError from '@/components/input-error';
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
import { store } from '@/routes/teams';

type Props = PropsWithChildren<{
    /** Control the dialog from outside, for opening it without a trigger. */
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
}>;

export default function CreateTeamModal({
    children,
    open: controlledOpen,
    onOpenChange,
}: Props) {
    const [uncontrolledOpen, setUncontrolledOpen] = useState(false);
    const open = controlledOpen ?? uncontrolledOpen;
    const setOpen = onOpenChange ?? setUncontrolledOpen;

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            {children ? (
                <DialogTrigger asChild>{children}</DialogTrigger>
            ) : null}
            <DialogContent>
                <Form
                    key={String(open)}
                    {...store.form()}
                    className="space-y-6"
                    onSuccess={() => setOpen(false)}
                >
                    {({ errors, processing }) => (
                        <>
                            <DialogHeader>
                                <DialogTitle>Create a new team</DialogTitle>
                                <DialogDescription>
                                    Create a new team to collaborate with
                                    others.
                                </DialogDescription>
                            </DialogHeader>

                            <div className="grid gap-2">
                                <Label htmlFor="name">Team name</Label>
                                <Input
                                    id="name"
                                    name="name"
                                    data-test="create-team-name"
                                    placeholder="My team"
                                    required
                                />
                                <InputError message={errors.name} />
                            </div>

                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button variant="secondary">Cancel</Button>
                                </DialogClose>

                                <Button
                                    type="submit"
                                    data-test="create-team-submit"
                                    disabled={processing}
                                >
                                    Create team
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
