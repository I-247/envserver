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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { store } from '@/routes/teams/webhooks';
import type { Team, WebhookOption } from '@/types';

type Props = PropsWithChildren<{
    team: Team;
    kinds: WebhookOption[];
    events: WebhookOption[];
}>;

/**
 * Adds a place the team's audit events are sent to.
 *
 * Leaving every event unchecked means all of them, which is both the useful
 * default for a chat channel and the one that keeps working when an action is
 * added to the trail later.
 */
export default function AddWebhookModal({
    team,
    kinds,
    events,
    children,
}: Props) {
    const [open, setOpen] = useState(false);
    const [kind, setKind] = useState(kinds[0]?.value ?? 'json');
    const [selected, setSelected] = useState<string[]>([]);

    const handleOpenChange = (nextOpen: boolean) => {
        setOpen(nextOpen);

        if (!nextOpen) {
            setKind(kinds[0]?.value ?? 'json');
            setSelected([]);
        }
    };

    const toggle = (value: string, checked: boolean) => {
        setSelected((current) =>
            checked
                ? [...current, value]
                : current.filter((event) => event !== value),
        );
    };

    return (
        <Dialog open={open} onOpenChange={handleOpenChange}>
            <DialogTrigger asChild>{children}</DialogTrigger>
            <DialogContent className="max-h-[90vh] overflow-y-auto">
                <Form
                    key={String(open)}
                    {...store.form(team.slug)}
                    className="space-y-6"
                    onSuccess={() => handleOpenChange(false)}
                >
                    {({ errors, processing }) => (
                        <>
                            <DialogHeader>
                                <DialogTitle>Add an endpoint</DialogTitle>
                                <DialogDescription>
                                    Every audit entry this team writes is sent
                                    here. The body carries names, counts and
                                    slugs — never a value.
                                </DialogDescription>
                            </DialogHeader>

                            <div className="grid gap-2">
                                <Label htmlFor="webhook-name">Name</Label>
                                <Input
                                    id="webhook-name"
                                    name="name"
                                    data-test="webhook-name"
                                    placeholder="Ops channel"
                                    autoFocus
                                />
                                <InputError message={errors.name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="webhook-kind">Format</Label>
                                <input type="hidden" name="kind" value={kind} />
                                <Select value={kind} onValueChange={setKind}>
                                    <SelectTrigger
                                        id="webhook-kind"
                                        data-test="webhook-kind"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {kinds.map((option) => (
                                            <SelectItem
                                                key={option.value}
                                                value={option.value}
                                            >
                                                {option.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <p className="text-sm text-muted-foreground">
                                    Signed JSON carries an
                                    <code className="mx-1">
                                        X-Kluis-Signature
                                    </code>
                                    header. Slack gets a single line of text,
                                    because that is all it reads.
                                </p>
                                <InputError message={errors.kind} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="webhook-url">URL</Label>
                                <Input
                                    id="webhook-url"
                                    name="url"
                                    data-test="webhook-url"
                                    className="font-mono"
                                    spellCheck={false}
                                    placeholder="https://hooks.slack.com/services/..."
                                />
                                <p className="text-sm text-muted-foreground">
                                    https only, and not an address on the
                                    server&apos;s own network.
                                </p>
                                <InputError message={errors.url} />
                            </div>

                            <div className="grid gap-2">
                                <Label>Events</Label>
                                <div className="grid max-h-56 gap-2 overflow-y-auto rounded-lg border p-3">
                                    {events.map((event) => (
                                        <label
                                            key={event.value}
                                            className="flex items-center gap-2 text-sm"
                                        >
                                            <Checkbox
                                                checked={selected.includes(
                                                    event.value,
                                                )}
                                                onCheckedChange={(checked) =>
                                                    toggle(
                                                        event.value,
                                                        checked === true,
                                                    )
                                                }
                                            />
                                            {event.label}
                                        </label>
                                    ))}
                                </div>
                                {selected.map((event) => (
                                    <input
                                        key={event}
                                        type="hidden"
                                        name="events[]"
                                        value={event}
                                    />
                                ))}
                                <p className="text-sm text-muted-foreground">
                                    Choose nothing to receive everything,
                                    including actions added to Kluis later.
                                </p>
                                <InputError message={errors.events} />
                            </div>

                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button variant="secondary">Cancel</Button>
                                </DialogClose>
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    data-test="webhook-submit"
                                >
                                    Add endpoint
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
