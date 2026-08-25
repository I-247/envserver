import { Head } from '@inertiajs/react';
import { Check, Terminal } from 'lucide-react';
import { Button } from '@/components/ui/button';

type Props = {
    authToken: string;
    client: { name: string };
    scopes: { id: string; description: string }[];
};

export default function DeviceAuthorize({ authToken, client, scopes }: Props) {
    return (
        <>
            <Head title="Authorize device" />

            <div className="space-y-6">
                <div className="flex items-center gap-3 rounded-lg border p-4">
                    <Terminal className="size-5 text-muted-foreground" />
                    <div>
                        <p className="font-medium">{client.name}</p>
                        <p className="text-sm text-muted-foreground">
                            wants access to your Kluis account
                        </p>
                    </div>
                </div>

                <ul className="space-y-2">
                    {scopes.map((scope) => (
                        <li
                            key={scope.id}
                            className="flex items-start gap-2 text-sm"
                        >
                            <Check className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                            <span>{scope.description}</span>
                        </li>
                    ))}
                </ul>

                <div className="flex gap-2">
                    <form
                        method="POST"
                        action="/oauth/device/authorize"
                        className="flex-1"
                    >
                        <input
                            type="hidden"
                            name="auth_token"
                            value={authToken}
                        />
                        <Button type="submit" className="w-full">
                            Authorize
                        </Button>
                    </form>

                    <form
                        method="POST"
                        action="/oauth/device/authorize"
                        className="flex-1"
                    >
                        <input type="hidden" name="_method" value="DELETE" />
                        <input
                            type="hidden"
                            name="auth_token"
                            value={authToken}
                        />
                        <Button
                            type="submit"
                            variant="secondary"
                            className="w-full"
                        >
                            Cancel
                        </Button>
                    </form>
                </div>
            </div>
        </>
    );
}

DeviceAuthorize.layout = {
    title: 'Authorize device',
    description: 'Only continue if you started this from your own terminal.',
};
