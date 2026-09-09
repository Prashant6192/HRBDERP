import { Head, Link, useForm } from '@inertiajs/react';
import { KeyRound, ShieldCheck } from 'lucide-react';
import { useRef } from 'react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { dashboard } from '@/routes';
import { index, unlock, verify } from '@/routes/formulas';
import pin from '@/routes/formulas/pin';

export default function UnlockFormulas({
    requirePin,
    ttlMinutes,
    lockedForMinutes,
    intended: _intended,
}: {
    requirePin: boolean;
    ttlMinutes: number;
    lockedForMinutes: number | null;
    intended: string | null;
}) {
    const input = useRef<HTMLInputElement>(null);
    const form = useForm({ secret: '' });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(verify().url, {
            onError: () => {
                form.reset('secret');
                input.current?.focus();
            },
        });
    };

    return (
        <>
            <Head title="Unlock formulations" />
            <div className="flex flex-1 items-start justify-center p-4 sm:p-6">
                <Card className="w-full max-w-md">
                    <CardHeader>
                        <div className="bg-primary/10 text-primary mb-2 flex size-10 items-center justify-center rounded-full">
                            <ShieldCheck className="size-5" />
                        </div>
                        <CardTitle>Unlock formulations</CardTitle>
                        <CardDescription>
                            Recipes are the company&apos;s trade secret.{' '}
                            {requirePin
                                ? 'Enter your formula PIN'
                                : 'Re-enter your account password'}{' '}
                            to open them for the next {ttlMinutes} minutes.
                            Every unlock is recorded.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {lockedForMinutes !== null && (
                            <Alert variant="destructive" className="mb-4">
                                <AlertTitle>Temporarily locked</AlertTitle>
                                <AlertDescription>
                                    Too many failed attempts. Try again in{' '}
                                    {lockedForMinutes}{' '}
                                    {lockedForMinutes === 1
                                        ? 'minute'
                                        : 'minutes'}
                                    .
                                </AlertDescription>
                            </Alert>
                        )}

                        <form onSubmit={submit} className="space-y-4">
                            <div className="space-y-2">
                                <Label htmlFor="secret">
                                    {requirePin ? 'Formula PIN' : 'Password'}
                                </Label>
                                <PasswordInput
                                    id="secret"
                                    ref={input}
                                    value={form.data.secret}
                                    onChange={(e) =>
                                        form.setData('secret', e.target.value)
                                    }
                                    inputMode={requirePin ? 'numeric' : 'text'}
                                    autoComplete={
                                        requirePin
                                            ? 'one-time-code'
                                            : 'current-password'
                                    }
                                    autoFocus
                                    required
                                />
                                <InputError message={form.errors.secret} />
                            </div>

                            <Button
                                type="submit"
                                className="w-full"
                                disabled={
                                    form.processing || lockedForMinutes !== null
                                }
                            >
                                <KeyRound className="size-4" />
                                Unlock for {ttlMinutes} minutes
                            </Button>
                        </form>

                        <div className="text-muted-foreground mt-4 flex flex-wrap justify-between gap-2 text-sm">
                            {requirePin && (
                                <Link
                                    href={pin.edit()}
                                    className="underline-offset-4 hover:underline"
                                >
                                    Set or change your PIN
                                </Link>
                            )}
                            <Link
                                href={index()}
                                className="underline-offset-4 hover:underline"
                            >
                                Back to formulas
                            </Link>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

UnlockFormulas.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Formulas', href: index() },
        { title: 'Unlock', href: unlock() },
    ],
};
