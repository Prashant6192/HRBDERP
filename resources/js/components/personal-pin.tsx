import { Form } from '@inertiajs/react';
import { KeyRound } from 'lucide-react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import PasswordInput from '@/components/password-input';
import { update } from '@/routes/personal-pin';

/**
 * One PIN per person, re-entered wherever a decision must be signed rather
 * than clicked: passing or failing a batch at QC, opening a formulation.
 */
export function PersonalPin({
    hasPin,
    pinSetAt,
    uses,
}: {
    hasPin: boolean;
    pinSetAt: string | null;
    uses: string[];
}) {
    return (
        <div className="space-y-6">
            <Heading
                variant="small"
                title="Personal PIN"
                description={`Four to eight digits, asked for when ${uses.length > 0 ? uses.join(' and ') : 'a decision must be signed'}. ${
                    hasPin
                        ? `Set ${pinSetAt ? new Date(pinSetAt).toLocaleDateString('en-IN') : 'earlier'}.`
                        : 'You have not set one yet.'
                }`}
            />

            <Form
                {...update.form()}
                options={{ preserveScroll: true }}
                resetOnSuccess
                className="space-y-6"
            >
                {({ errors, processing }) => (
                    <>
                        <div className="grid gap-2">
                            <Label htmlFor="pin-password">
                                Your account password
                            </Label>
                            <PasswordInput
                                id="pin-password"
                                name="password"
                                autoComplete="current-password"
                                className="mt-1 block w-full"
                            />
                            <InputError message={errors.password} />
                        </div>

                        <div className="grid gap-2 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="pin">
                                    {hasPin ? 'New PIN' : 'PIN'}
                                </Label>
                                <Input
                                    id="pin"
                                    name="pin"
                                    type="password"
                                    inputMode="numeric"
                                    autoComplete="off"
                                    maxLength={8}
                                    className="mt-1 block w-full tracking-widest"
                                />
                                <InputError message={errors.pin} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="pin_confirmation">
                                    Repeat PIN
                                </Label>
                                <Input
                                    id="pin_confirmation"
                                    name="pin_confirmation"
                                    type="password"
                                    inputMode="numeric"
                                    autoComplete="off"
                                    maxLength={8}
                                    className="mt-1 block w-full tracking-widest"
                                />
                            </div>
                        </div>

                        <Button type="submit" disabled={processing}>
                            <KeyRound className="size-4" />
                            {hasPin ? 'Change PIN' : 'Set PIN'}
                        </Button>
                    </>
                )}
            </Form>
        </div>
    );
}
