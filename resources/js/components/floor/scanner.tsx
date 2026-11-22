import { Keyboard } from 'lucide-react';
import { useEffect, useRef, useState, type FormEvent } from 'react';
import { BarcodeCamera } from '@/components/barcode-camera';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

/**
 * Reads a barcode or QR code with the phone's camera, in every browser,
 * and always offers the keyboard: a code can be typed or pasted, and a
 * handheld (wedge) scanner types too. With `captureKeys`, a handheld
 * scanner is read wherever the cursor is — nobody has to click the box
 * first — and a bare Enter is passed on as `onEnter`.
 */
export function Scanner({
    onCode,
    busy = false,
    placeholder = 'Scan or type a code…',
    autoStart = true,
    repeatMs,
    captureKeys = false,
    onEnter,
}: {
    onCode: (code: string) => void;
    busy?: boolean;
    placeholder?: string;
    autoStart?: boolean;
    repeatMs?: number;
    captureKeys?: boolean;
    onEnter?: () => void;
}) {
    const [typed, setTyped] = useState('');
    const live = useRef({ onCode, onEnter });

    useEffect(() => {
        live.current = { onCode, onEnter };
    }, [onCode, onEnter]);

    useEffect(() => {
        if (!captureKeys) {
            return;
        }

        let buffer = '';
        let at = 0;

        const onKey = (e: KeyboardEvent) => {
            const el = e.target as HTMLElement | null;

            if (
                e.ctrlKey ||
                e.metaKey ||
                e.altKey ||
                (el &&
                    (el.tagName === 'INPUT' ||
                        el.tagName === 'TEXTAREA' ||
                        el.tagName === 'SELECT' ||
                        el.isContentEditable))
            ) {
                return;
            }

            const now = Date.now();

            // A scanner types its code in a burst; a pause starts a new one.
            if (now - at > 300) {
                buffer = '';
            }

            at = now;

            if (e.key === 'Enter') {
                const code = buffer.trim();
                buffer = '';

                if (code.length >= 3) {
                    e.preventDefault();
                    live.current.onCode(code);
                } else if (el?.tagName !== 'BUTTON' && el?.tagName !== 'A') {
                    e.preventDefault();
                    live.current.onEnter?.();
                }
            } else if (e.key.length === 1) {
                buffer += e.key;
            }
        };

        document.addEventListener('keydown', onKey);

        return () => document.removeEventListener('keydown', onKey);
    }, [captureKeys]);

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
            <BarcodeCamera
                onCode={onCode}
                busy={busy}
                autoStart={autoStart}
                repeatMs={repeatMs}
            />
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
