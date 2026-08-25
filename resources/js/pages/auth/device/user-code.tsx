import { Head } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Props = {
    request?: { user_code?: string };
    errors?: Record<string, string>;
};

export default function DeviceUserCode({ request, errors }: Props) {
    return (
        <>
            <Head title="Connect a device" />

            <form
                method="GET"
                action="/oauth/device/authorize"
                className="space-y-6"
            >
                <div className="grid gap-2">
                    <Label htmlFor="user_code">Code</Label>
                    <Input
                        id="user_code"
                        name="user_code"
                        defaultValue={request?.user_code ?? ''}
                        placeholder="XXXX-XXXX"
                        autoComplete="off"
                        autoFocus
                        required
                        className="text-center font-mono text-lg tracking-[0.3em] uppercase"
                    />
                    <InputError message={errors?.user_code} />
                </div>

                <Button type="submit" className="w-full">
                    Continue
                </Button>
            </form>
        </>
    );
}

DeviceUserCode.layout = {
    title: 'Connect a device',
    description:
        'Enter the code shown in your terminal to link it to your account.',
};
