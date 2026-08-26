import { Form } from '@inertiajs/react';
import { ShieldAlert } from 'lucide-react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { update } from '@/routes/teams/two-factor';
import type { Team } from '@/types';

type Props = {
    team: Team;
};

export default function TeamTwoFactorRequirement({ team }: Props) {
    const required = team.twoFactorRequired ?? false;
    const unenrolled = team.membersWithoutSecondFactor ?? 0;

    return (
        <div className="space-y-6">
            <Heading
                variant="small"
                title="Two-factor authentication"
                description="Require every member to sign in with a second factor before they can reach this team's vault. An authenticator app or a passkey both count."
            />

            {!required && unenrolled > 0 ? (
                <Alert>
                    <ShieldAlert />
                    <AlertDescription>
                        {unenrolled === 1
                            ? '1 member has neither an authenticator app nor a passkey, and will be sent to their security settings the moment you turn this on.'
                            : `${unenrolled} members have neither an authenticator app nor a passkey, and will be sent to their security settings the moment you turn this on.`}
                    </AlertDescription>
                </Alert>
            ) : null}

            <Form
                {...update.form(team.slug)}
                options={{ preserveScroll: true }}
                className="space-y-6"
            >
                {({ errors, processing }) => (
                    <>
                        <input
                            type="hidden"
                            name="two_factor_required"
                            value={required ? '0' : '1'}
                        />

                        <div className="grid max-w-sm gap-2">
                            <Label htmlFor="team-two-factor-password">
                                Password
                            </Label>

                            <PasswordInput
                                id="team-two-factor-password"
                                name="password"
                                placeholder="Your password"
                                autoComplete="current-password"
                                data-test="team-two-factor-password"
                            />

                            <p className="text-sm text-muted-foreground">
                                {required
                                    ? 'Lifting the requirement lets members back in without a second factor, so it is confirmed every time.'
                                    : 'Confirm with your password. This is asked again on every change, never remembered.'}
                            </p>

                            <InputError message={errors.password} />
                        </div>

                        <div className="flex items-center gap-4">
                            <Button
                                type="submit"
                                variant={required ? 'outline' : 'default'}
                                data-test="team-two-factor-toggle"
                                disabled={processing}
                            >
                                {required
                                    ? 'Stop requiring two-factor'
                                    : 'Require two-factor'}
                            </Button>

                            <span className="text-sm text-muted-foreground">
                                {required
                                    ? 'Currently required'
                                    : 'Currently off'}
                            </span>
                        </div>

                        <InputError message={errors.two_factor_required} />
                    </>
                )}
            </Form>
        </div>
    );
}
