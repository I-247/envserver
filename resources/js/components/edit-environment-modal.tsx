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
import { Textarea } from '@/components/ui/textarea';
import { update } from '@/routes/environments';
import type { EnvironmentSummary } from '@/types';

type Props = PropsWithChildren<{
    teamSlug: string;
    projectSlug: string;
    environment: EnvironmentSummary;
}>;

export default function EditEnvironmentModal({
    teamSlug,
    projectSlug,
    environment,
    children,
}: Props) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>{children}</DialogTrigger>
            <DialogContent>
                <Form
                    key={String(open)}
                    {...update.form.patch([
                        teamSlug,
                        projectSlug,
                        environment.slug,
                    ])}
                    className="space-y-6"
                    onSuccess={() => setOpen(false)}
                >
                    {({ errors, processing }) => (
                        <>
                            <DialogHeader>
                                <DialogTitle>Edit environment</DialogTitle>
                                <DialogDescription>
                                    The slug stays{' '}
                                    <code>{environment.slug}</code> so deploy
                                    tokens and running servers keep working.
                                </DialogDescription>
                            </DialogHeader>

                            <div className="grid gap-2">
                                <Label htmlFor="edit-environment-name">
                                    Environment name
                                </Label>
                                <Input
                                    id="edit-environment-name"
                                    name="name"
                                    data-test="edit-environment-name"
                                    defaultValue={environment.name}
                                    required
                                />
                                <InputError message={errors.name} />
                            </div>

                            <div className="flex items-start space-x-3">
                                <Checkbox
                                    id="edit-environment-auto-publish"
                                    name="auto_publish"
                                    value="1"
                                    data-test="edit-environment-auto-publish"
                                    defaultChecked={environment.autoPublish}
                                    className="mt-0.5"
                                />
                                <div className="grid gap-1">
                                    <Label
                                        htmlFor="edit-environment-auto-publish"
                                        className="cursor-pointer"
                                    >
                                        Publish changes automatically
                                    </Label>
                                    <p className="text-sm text-muted-foreground">
                                        Turning this off does not undo anything
                                        already published; from here on a change
                                        waits as a pending change instead.
                                    </p>
                                </div>
                            </div>
                            <InputError message={errors.auto_publish} />

                            <div className="grid gap-2">
                                <Label htmlFor="edit-environment-ip-allowlist">
                                    Deploy token IP restriction
                                </Label>
                                <Textarea
                                    id="edit-environment-ip-allowlist"
                                    name="ip_allowlist"
                                    data-test="edit-environment-ip-allowlist"
                                    defaultValue={(
                                        environment.ipAllowList ?? []
                                    ).join('\n')}
                                    rows={3}
                                    spellCheck={false}
                                    className="font-mono"
                                    placeholder={'203.0.113.4\n10.0.0.0/8'}
                                />
                                <p className="text-sm text-muted-foreground">
                                    One IP address or CIDR range per line. Only
                                    deploy tokens pulling from these addresses
                                    can download this environment. Leave it
                                    empty and any address with a valid token
                                    can.
                                </p>
                                <InputError message={errors.ip_allowlist} />
                            </div>

                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button variant="secondary">Cancel</Button>
                                </DialogClose>

                                <Button
                                    type="submit"
                                    data-test="edit-environment-submit"
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
