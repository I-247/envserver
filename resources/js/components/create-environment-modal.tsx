import { Form } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import { store } from '@/routes/environments';

type Props = PropsWithChildren<{
    teamSlug: string;
    projectSlug: string;
}>;

export default function CreateEnvironmentModal({
    teamSlug,
    projectSlug,
    children,
}: Props) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>{children}</DialogTrigger>
            <DialogContent>
                <Form
                    key={String(open)}
                    {...store.form([teamSlug, projectSlug])}
                    className="space-y-6"
                    onSuccess={() => setOpen(false)}
                >
                    {({ errors, processing }) => (
                        <>
                            <DialogHeader>
                                <DialogTitle>New environment</DialogTitle>
                                <DialogDescription>
                                    The environment starts empty. Its slug is
                                    derived from the name and never changes
                                    afterwards, because deploys address it by
                                    slug.
                                </DialogDescription>
                            </DialogHeader>

                            <div className="grid gap-2">
                                <Label htmlFor="environment-name">
                                    Environment name
                                </Label>
                                <Input
                                    id="environment-name"
                                    name="name"
                                    data-test="create-environment-name"
                                    placeholder="Acceptance"
                                    required
                                />
                                <InputError message={errors.name} />
                            </div>

                            <div className="flex items-start space-x-3">
                                {/* An unchecked box submits nothing at all, which
                                    is exactly the "off" the server reads. */}
                                <Checkbox
                                    id="environment-auto-publish"
                                    name="auto_publish"
                                    value="1"
                                    data-test="create-environment-auto-publish"
                                    defaultChecked
                                    className="mt-0.5"
                                />
                                <div className="grid gap-1">
                                    <Label
                                        htmlFor="environment-auto-publish"
                                        className="cursor-pointer"
                                    >
                                        Publish changes automatically
                                    </Label>
                                    <p className="text-sm text-muted-foreground">
                                        With this off a change waits as a
                                        pending change until someone publishes a
                                        release on purpose — the way production
                                        works.
                                    </p>
                                </div>
                            </div>
                            <InputError message={errors.auto_publish} />

                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button variant="secondary">Cancel</Button>
                                </DialogClose>

                                <Button
                                    type="submit"
                                    data-test="create-environment-submit"
                                    disabled={processing}
                                >
                                    Create environment
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
