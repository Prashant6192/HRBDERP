import { Form, Head } from '@inertiajs/react';
import { CheckCircle2 } from 'lucide-react';
import InputError from '@/components/input-error';
import PasskeyVerify from '@/components/passkey-verify';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

type Props = {
    status?: string;
    canResetPassword: boolean;
};

export default function Login({ status, canResetPassword }: Props) {
    return (
        <>
            <Head title="Log in" />

            {status && (
                <div className="border-primary/30 bg-primary/10 text-foreground mb-6 flex items-start gap-2 rounded-lg border px-3 py-2.5 text-sm">
                    <CheckCircle2 className="text-primary mt-0.5 size-4 shrink-0" />
                    <span>{status}</span>
                </div>
            )}

            {/* The passkey block paints its separator label with the page
                background so it can sit over its own rule; on the card that
                has to be the card. */}
            <div className="[--color-background:var(--color-card)]">
                <PasskeyVerify />
            </div>

            <Form
                {...store.form()}
                resetOnSuccess={['password']}
                className="flex flex-col gap-5"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-2">
                            <Label
                                htmlFor="email"
                                className="text-muted-foreground text-xs font-normal"
                            >
                                Email address
                            </Label>
                            <Input
                                id="email"
                                type="email"
                                name="email"
                                required
                                autoFocus
                                tabIndex={1}
                                autoComplete="email"
                                placeholder="you@company.com"
                            />
                            <InputError message={errors.email} />
                        </div>

                        <div className="grid gap-2">
                            <Label
                                htmlFor="password"
                                className="text-muted-foreground text-xs font-normal"
                            >
                                Password
                            </Label>
                            <PasswordInput
                                id="password"
                                name="password"
                                required
                                tabIndex={2}
                                autoComplete="current-password"
                                placeholder="Enter your password"
                            />
                            <InputError message={errors.password} />
                        </div>

                        <div className="flex items-center justify-between gap-4">
                            <Label
                                htmlFor="remember"
                                className="text-muted-foreground flex items-center gap-2.5 font-normal"
                            >
                                <Checkbox
                                    id="remember"
                                    name="remember"
                                    tabIndex={3}
                                />
                                Keep me signed in
                            </Label>
                            {canResetPassword && (
                                <TextLink
                                    href={request()}
                                    className="text-primary text-sm font-medium no-underline hover:underline"
                                    tabIndex={5}
                                >
                                    Forgot password?
                                </TextLink>
                            )}
                        </div>

                        <Button
                            type="submit"
                            className="mt-3 w-full"
                            tabIndex={4}
                            disabled={processing}
                            data-test="login-button"
                        >
                            {processing && <Spinner />}
                            Log in
                        </Button>
                    </>
                )}
            </Form>

            <p className="text-muted-foreground mt-8 text-center text-sm">
                Accounts are created by your administrator.
            </p>
        </>
    );
}

Login.layout = {
    title: 'Welcome back',
    description: 'Sign in to pick up where the floor left off.',
};
