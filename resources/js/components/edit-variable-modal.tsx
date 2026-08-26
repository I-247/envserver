import { Form } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import { useState } from 'react';
import Code from '@/components/code';
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
import environments from '@/routes/environments';
import type { EnvironmentVariable } from '@/types';

type Props = PropsWithChildren<{
    args: [string, string, string];
    variable: EnvironmentVariable;
}>;

/**
 * Changes a variable's value, description or alias.
 *
 * The current value is never prefilled: the page deliberately ships no
 * plaintext, and reading one is a decision the server gets to record. So the
 * field starts empty and an empty field means "leave it alone" rather than
 * "make it empty" — emptying a secret by accident is the more expensive
 * mistake, and a variable that should be gone can be removed instead.
 *
 * A borrowed variable only offers the alias. Its value and description belong
 * to the project that owns it, and are edited there.
 */
export default function EditVariableModal({ args, variable, children }: Props) {
    const [open, setOpen] = useState(false);

    /**
     * Send only what the form actually changed.
     *
     * Every field is `sometimes` on the server, and the controller keys off
     * the field being present, so leaving a field out is how "unchanged" is
     * expressed.
     */
    const onlyChanges = (data: Record<string, string>) => {
        const payload: Record<string, string | null> = {};

        if (data.value !== '') {
            payload.value = data.value;

            if (data.note.trim() !== '') {
                payload.note = data.note.trim();
            }
        }

        if (
            !variable.borrowed &&
            data.description !== (variable.description ?? '')
        ) {
            payload.description =
                data.description === '' ? null : data.description;
        }

        if (data.alias_key !== (variable.alias ?? '')) {
            payload.alias_key = data.alias_key === '' ? null : data.alias_key;
        }

        if (
            !variable.borrowed &&
            data.rotate_after_days !==
                String(variable.rotation.ownIntervalDays ?? '')
        ) {
            payload.rotate_after_days =
                data.rotate_after_days === '' ? null : data.rotate_after_days;
        }

        return payload;
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>{children}</DialogTrigger>
            <DialogContent>
                <Form
                    key={String(open)}
                    {...environments.variables.update.form.patch([
                        ...args,
                        variable.id,
                    ])}
                    transform={onlyChanges}
                    className="space-y-6"
                    onSuccess={() => setOpen(false)}
                >
                    {({ errors, processing }) => (
                        <>
                            <DialogHeader>
                                <DialogTitle>
                                    Edit <Code>{variable.key}</Code>
                                </DialogTitle>
                                <DialogDescription>
                                    {variable.borrowed && variable.owner
                                        ? `This variable belongs to ${variable.owner.name}. Only the name it gets in this environment can be changed here.`
                                        : `A new value is kept as version ${variable.version + 1}; the current one stays in the history.`}
                                </DialogDescription>
                            </DialogHeader>

                            {variable.borrowed ? null : (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor={`value-${variable.id}`}>
                                            New value
                                        </Label>
                                        <Input
                                            id={`value-${variable.id}`}
                                            name="value"
                                            data-test="edit-variable-value"
                                            className="font-mono"
                                            autoComplete="off"
                                            spellCheck={false}
                                            placeholder="Leave empty to keep the current value"
                                            autoFocus
                                        />
                                        <InputError message={errors.value} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor={`note-${variable.id}`}>
                                            Note
                                        </Label>
                                        <Input
                                            id={`note-${variable.id}`}
                                            name="note"
                                            data-test="edit-variable-note"
                                            placeholder="Why this changed (optional)"
                                        />
                                        <p className="text-sm text-muted-foreground">
                                            Stored with the new version and
                                            shown in the release it lands in.
                                        </p>
                                        <InputError message={errors.note} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label
                                            htmlFor={`rotate-${variable.id}`}
                                        >
                                            Rotate after
                                        </Label>
                                        <div className="flex items-center gap-2">
                                            <Input
                                                id={`rotate-${variable.id}`}
                                                name="rotate_after_days"
                                                data-test="edit-variable-rotate-after"
                                                type="number"
                                                min={1}
                                                max={3650}
                                                className="max-w-32"
                                                defaultValue={
                                                    variable.rotation
                                                        .ownIntervalDays ?? ''
                                                }
                                                placeholder={
                                                    variable.rotation
                                                        .intervalDays
                                                        ? String(
                                                              variable.rotation
                                                                  .intervalDays,
                                                          )
                                                        : 'off'
                                                }
                                            />
                                            <span className="text-sm text-muted-foreground">
                                                days
                                            </span>
                                        </div>
                                        <p className="text-sm text-muted-foreground">
                                            Leave empty to follow the team
                                            policy.
                                        </p>
                                        <InputError
                                            message={errors.rotate_after_days}
                                        />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label
                                            htmlFor={`description-${variable.id}`}
                                        >
                                            Description
                                        </Label>
                                        <Input
                                            id={`description-${variable.id}`}
                                            name="description"
                                            data-test="edit-variable-description"
                                            defaultValue={
                                                variable.description ?? ''
                                            }
                                        />
                                        <InputError
                                            message={errors.description}
                                        />
                                    </div>
                                </>
                            )}

                            <div className="grid gap-2">
                                <Label htmlFor={`alias-${variable.id}`}>
                                    Name in this environment
                                </Label>
                                <Input
                                    id={`alias-${variable.id}`}
                                    name="alias_key"
                                    data-test="edit-variable-alias"
                                    className="font-mono"
                                    spellCheck={false}
                                    defaultValue={variable.alias ?? ''}
                                    placeholder={variable.ownKey}
                                    autoFocus={variable.borrowed}
                                />
                                <p className="text-sm text-muted-foreground">
                                    Leave empty to use{' '}
                                    <Code>{variable.ownKey}</Code>. Only this
                                    environment sees the alias.
                                </p>
                                <InputError message={errors.alias_key} />
                            </div>

                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button variant="secondary">Cancel</Button>
                                </DialogClose>
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    data-test="edit-variable-submit"
                                >
                                    Save
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
