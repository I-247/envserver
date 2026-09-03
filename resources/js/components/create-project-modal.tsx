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
import { store } from '@/routes/projects';

type Props = PropsWithChildren<{
    teamSlug: string;
}>;

export default function CreateProjectModal({ teamSlug, children }: Props) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>{children}</DialogTrigger>
            <DialogContent>
                <Form
                    key={String(open)}
                    {...store.form(teamSlug)}
                    className="space-y-6"
                    onSuccess={() => setOpen(false)}
                >
                    {({ errors, processing }) => (
                        <>
                            <DialogHeader>
                                <DialogTitle>New project</DialogTitle>
                                <DialogDescription>
                                    A project starts with a development, staging
                                    and production environment.
                                </DialogDescription>
                            </DialogHeader>

                            <div className="grid gap-2">
                                <Label htmlFor="name">Project name</Label>
                                <Input
                                    id="name"
                                    name="name"
                                    data-test="create-project-name"
                                    placeholder="Webshop"
                                    required
                                />
                                <InputError message={errors.name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="description">
                                    Description{' '}
                                    <span className="text-muted-foreground">
                                        (optional)
                                    </span>
                                </Label>
                                <Input
                                    id="description"
                                    name="description"
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
                                    data-test="create-project-submit"
                                    disabled={processing}
                                >
                                    Create project
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
