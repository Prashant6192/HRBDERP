import { Head, useForm } from '@inertiajs/react';
import { KeyRound } from 'lucide-react';
import { useRef } from 'react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
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
import { index } from '@/routes/formulas';
import pin from '@/routes/formulas/pin';

export default function FormulaPin({
    hasPin,
    pinSetAt,
    ttlMinutes,
}: {
    hasPin: boolean;
    pinSetAt: string | null;
    ttlMinutes: number;
}) {
    const passwordInput = useRef<HTMLInputElement>(null);
    const pinInput = useRef<HTMLInputElement>(null);

    const form = useForm({
        password: '',
        pin: '',
        pin_confirmation: '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(pin.update().url, {
            onError: (errors) => {
                if (errors.password) {
                    form.reset('password');
                    passwordInput.current?.focus();
                } else {
                    form.reset('pin', 'pin_confirmation');
                    pinInput.current?.focus();
                }
            },
        });
    };

    return (
        <>
            <Head title={hasPin ? 'Change formula PIN' : 'Set formula PIN'} />
            <div className="flex flex-1 items-start justify-center p-4 sm:p-6">
                <Card className="w-full max-w-md">
                    <CardHeader>
                        <div className="bg-primary/10 text-primary mb-2 flex size-10 items-center justify-center rounded-full">
                            <KeyRound className="size-5" />
                        </div>
                        <CardTitle>
                            {hasPin
                                ? 'Change your formula PIN'
                                : 'Set your formula PIN'}
                        </CardTitle>
                        <CardDescription>
                            Your PIN is a second check, separate from your
                            password, that you enter to open recipes for{' '}
                            {ttlMinutes} minutes at a time. It is stored only as
                            a hash — nobody, including administrators, can read
                            it back.
                            {hasPin && pinSetAt && (
                                <>
                                    {' '}
                                    Last set on{' '}
                                    {new Date(pinSetAt).toLocaleDateString(
                                        'en-IN',
                                        {
                                            day: 'numeric',
                                            month: 'short',
                                            year: 'numeric',
                                        },
                                    )}
                                    .
                                </>
                            )}
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={submit} className="space-y-4">
                            <div className="space-y-2">
                                <Label htmlFor="password">
                                    Your account password
                                </Label>
                                <PasswordInput
                                    id="password"
                                    ref={passwordInput}
                                    value={form.data.password}
                                    onChange={(e) =>
                                        form.setData('password', e.target.value)
                                    }
                                    autoComplete="current-password"
                                    autoFocus
                                    required
                                />
                                <InputError message={form.errors.password} />
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="pin">
                                    New PIN (4–8 digits)
                                </Label>
                                <PasswordInput
                                    id="pin"
                                    ref={pinInput}
                                    value={form.data.pin}
                                    onChange={(e) =>
                                        form.setData(
                                            'pin',
                                            e.target.value.replace(/\D/g, ''),
                                        )
                                    }
                                    inputMode="numeric"
                                    autoComplete="new-password"
                                    maxLength={8}
                                    required
                                />
                                <InputError message={form.errors.pin} />
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="pin_confirmation">
                                    Confirm PIN
                                </Label>
                                <PasswordInput
                                    id="pin_confirmation"
                                    value={form.data.pin_confirmation}
                                    onChange={(e) =>
                                        form.setData(
                                            'pin_confirmation',
                                            e.target.value.replace(/\D/g, ''),
                                        )
                                    }
                                    inputMode="numeric"
                                    autoComplete="new-password"
                                    maxLength={8}
                                    required
                                />
                            </div>

                            <Button
                                type="submit"
                                className="w-full"
                                disabled={form.processing}
                            >
                                {hasPin ? 'Change PIN' : 'Set PIN'}
                            </Button>
                        </form>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

FormulaPin.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Formulas', href: index() },
        { title: 'Formula PIN', href: pin.edit() },
    ],
};
