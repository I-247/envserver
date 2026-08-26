import { Form } from '@inertiajs/react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { update } from '@/routes/teams/rotation-policy';
import type { Team } from '@/types';

type Props = {
    team: Team;
};

export default function TeamRotationPolicy({ team }: Props) {
    const days = team.defaultRotateAfterDays ?? null;

    return (
        <div className="space-y-6">
            <Heading
                variant="small"
                title="Rotation policy"
                description="How long a value may stand before Kluis calls it out. It never changes a secret by itself — it only tells you which ones have been sitting still."
            />

            <Form
                {...update.form(team.slug)}
                options={{ preserveScroll: true }}
                className="space-y-6"
            >
                {({ errors, processing }) => (
                    <>
                        <div className="grid gap-2">
                            <Label htmlFor="team-rotation-policy">
                                Flag secrets after
                            </Label>
                            <div className="flex items-center gap-2">
                                <Input
                                    id="team-rotation-policy"
                                    name="default_rotate_after_days"
                                    data-test="team-rotation-policy-input"
                                    type="number"
                                    min={1}
                                    max={3650}
                                    defaultValue={days ?? ''}
                                    placeholder="90"
                                    className="max-w-32"
                                />
                                <span className="text-sm text-muted-foreground">
                                    days
                                </span>
                            </div>
                            <p className="text-sm text-muted-foreground">
                                Leave it empty to turn the policy off. A single
                                variable can name its own interval, which is
                                what a build number or a public URL wants rather
                                than this number.
                            </p>
                            <InputError
                                message={errors.default_rotate_after_days}
                            />
                        </div>

                        <div className="flex items-center gap-4">
                            <Button
                                type="submit"
                                data-test="team-rotation-policy-save"
                                disabled={processing}
                            >
                                Save
                            </Button>

                            <span className="text-sm text-muted-foreground">
                                {days === null
                                    ? 'Currently off'
                                    : `Currently ${days} ${days === 1 ? 'day' : 'days'}`}
                            </span>
                        </div>
                    </>
                )}
            </Form>
        </div>
    );
}
