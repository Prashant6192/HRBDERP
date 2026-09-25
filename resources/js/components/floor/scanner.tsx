import { Keyboard } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { BarcodeCamera } from '@/components/barcode-camera';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

/**
 * Reads a barcode or QR code with the phone's camera, in every browser,
 * and always offers the keyboard: a code can be typed or pasted, and a
 * handheld (wedge) scanner types too.
 */
export function Scanner({
    onCode,
    busy = false,
    placeholder = 'Scan or type a code…',
    autoStart = true,
}: {
    onCode: (code: string) => void;
    busy?: boolean;
    placeholder?: string;
    autoStart?: boolean;
}) {
    const [typed, setTyped] = useState('');

    const submit = (e: FormEvent) => {
        e.preventDefault();

        if (typed.trim() === '') {
            return;
        }

        onCode(typed.trim());
        setTyped('');
    };

    return (
        <div className="space-y-3">
            <BarcodeCamera onCode={onCode} busy={busy} autoStart={autoStart} />
            <form onSubmit={submit} className="flex gap-2">
                <div className="relative flex-1">
                    <Keyboard className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                    <Input
                        value={typed}
                        onChange={(e) => setTyped(e.target.value)}
                        placeholder={placeholder}
                        className="h-12 pl-9 text-base"
                        autoComplete="off"
                        autoCapitalize="characters"
                        inputMode="text"
                    />
                </div>
                <Button
                    type="submit"
                    className="h-12"
                    disabled={busy || typed.trim() === ''}
                >
                    Go
                </Button>
            </form>
        </div>
    );
}
