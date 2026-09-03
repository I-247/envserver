// Components
import { Form, Head } from '@inertiajs/react';
import { CheckCircle2, MailCheck } from 'lucide-react';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { logout } from '@/routes';
import { send } from '@/routes/verification';

export default function VerifyEmail({ status }: { status?: string }) {
    return (
        <>
            <Head title="Email verification" />

            <div className="mx-auto mb-2 flex size-14 items-center justify-center rounded-full bg-[#171512]">
                <MailCheck className="size-7 text-[#e6b84c]" />
            </div>

            {status === 'verification-link-sent' && (
                <div className="mb-4 flex flex-col items-center gap-2 rounded-lg border border-green-600/20 bg-green-600/10 px-4 py-3 text-center text-sm font-medium text-green-600">
                    <CheckCircle2 className="size-4" />
                    A new verification link has been sent to the email
                    address you provided during registration.
                </div>
            )}

            <Form {...send.form()} className="space-y-6 text-center">
                {({ processing }) => (
                    <>
                        <Button disabled={processing} variant="secondary">
                            {processing && <Spinner />}
                            Resend verification email
                        </Button>

                        <TextLink
                            href={logout()}
                            className="mx-auto block text-sm"
                        >
                            Log out
                        </TextLink>
                    </>
                )}
            </Form>
        </>
    );
}

VerifyEmail.layout = {
    title: 'Email verification',
    description:
        'Please verify your email address by clicking on the link we just emailed to you.',
};
