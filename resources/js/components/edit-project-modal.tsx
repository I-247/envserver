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
import { update } from '@/routes/projects';

type Props = PropsWithChildren<{
    teamSlug: string;
    project: {
        name: string;
        slug: string;
        description: string | null;
    };
}>;

export default function EditProjectModal({
    teamSlug,
    project,
    children,
}: Props) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>{children}</DialogTrigger>
            <DialogContent>
                <Form
                    key={String(open)}
                    {...update.form.patch([teamSlug, project.slug])}
                    className="space-y-6"
                    onSuccess={() => setOpen(false)}
                >
                    {({ errors, processing }) => (
                        <>
                            <DialogHeader>
                                <DialogTitle>Edit project</DialogTitle>
                                <DialogDescription>
                                    The project slug stays{' '}
                                    <code>{project.slug}</code> so existing
                                    deploys keep working.
                                </DialogDescription>
                            </DialogHeader>

                            <div className="grid gap-2">
                                <Label htmlFor="edit-project-name">
                                    Project name
                                </Label>
                                <Input
                                    id="edit-project-name"
                                    name="name"
                                    data-test="edit-project-name"
                                    defaultValue={project.name}
                                    required
                                />
                                <InputError message={errors.name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="edit-project-description">
                                    Description{' '}
                                    <span className="text-muted-foreground">
                                        (optional)
                                    </span>
                                </Label>
                                <Input
                                    id="edit-project-description"
                                    name="description"
                                    data-test="edit-project-description"
                                    defaultValue={project.description ?? ''}
                                    placeholder="What this project is for"
                                />
                                <InputError message={errors.description} />
                            </div>

                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button variant="secondary">Cancel</Button>
                                </DialogClose>

                                <Button
                                    type="submit"
                                    data-test="edit-project-submit"
                                    disabled={processing}
                                >
                                    Save changes
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
