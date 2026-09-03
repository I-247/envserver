import { Form } from '@inertiajs/react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { update } from '@/routes/teams/ip-allowlist';
import type { Team } from '@/types';

type Props = {
    team: Team;
};

export default function TeamIpAllowList({ team }: Props) {
    const entries = team.ipAllowList ?? [];

    return (
        <div className="space-y-6">
            <Heading
                variant="small"
                title="IP restriction"
                description="Limit which networks this team can be reached from. Leave it empty and the team is reachable from anywhere you can sign in."
            />

            <Form
                {...update.form(team.slug)}
                options={{ preserveScroll: true }}
                className="space-y-6"
            >
                {({ errors, processing }) => (
                    <>
                        <div className="grid gap-2">
                            <Label htmlFor="team-ip-allowlist">
                                Allowed addresses
                            </Label>
                            <Textarea
                                id="team-ip-allowlist"
                                name="ip_allowlist"
                                data-test="team-ip-allowlist-input"
                                defaultValue={entries.join('\n')}
                                rows={4}
                                spellCheck={false}
                                className="font-mono"
                                placeholder={
                                    '203.0.113.4\n10.0.0.0/8\n2001:db8::/32'
                                }
                            />
                            <p className="text-sm text-muted-foreground">
                                One IP address or CIDR range per line. Your own
                                address has to be on the list, otherwise saving
                                would lock you out. Deploy tokens are not
                                covered here — restrict those per environment.
                            </p>
                            <InputError message={errors.ip_allowlist} />
                        </div>

                        <div className="flex items-center gap-4">
                            <Button
                                type="submit"
                                data-test="team-ip-allowlist-save"
                                disabled={processing}
                            >
                                Save
                            </Button>

                            {entries.length === 0 ? (
                                <span className="text-sm text-muted-foreground">
                                    Currently off
                                </span>
                            ) : (
                                <span className="text-sm text-muted-foreground">
                                    {entries.length === 1
                                        ? '1 entry allowed'
                                        : `${entries.length} entries allowed`}
                                </span>
                            )}
                        </div>
                    </>
                )}
            </Form>
        </div>
    );
}
