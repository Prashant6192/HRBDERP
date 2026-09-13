import { Camera, CameraOff, Keyboard } from 'lucide-react';
import { useEffect, useRef, useState, type FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type Detector = {
    detect: (source: ImageBitmapSource) => Promise<{ rawValue: string }[]>;
};

declare global {
    interface Window {
        BarcodeDetector?: new (options?: { formats?: string[] }) => Detector;
    }
}

/**
 * Reads a QR code with the phone's camera where the browser can (the
 * BarcodeDetector API), and always offers the keyboard: a code can be
 * typed or pasted, and a wedge scanner types too.
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
    const video = useRef<HTMLVideoElement | null>(null);
    const stream = useRef<MediaStream | null>(null);
    const [supported] = useState(
        () =>
            typeof window !== 'undefined' &&
            Boolean(window.BarcodeDetector) &&
            typeof navigator.mediaDevices?.getUserMedia === 'function',
    );
    const [active, setActive] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [typed, setTyped] = useState('');
    const last = useRef<{ code: string; at: number }>({ code: '', at: 0 });

    const stop = () => {
        stream.current?.getTracks().forEach((t) => t.stop());
        stream.current = null;
        setActive(false);
    };

    const start = async () => {
        if (!supported || !window.BarcodeDetector) return;
        try {
            const media = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: { ideal: 'environment' } },
                audio: false,
            });
            stream.current = media;
            if (video.current) {
                video.current.srcObject = media;
                await video.current.play();
            }
            setActive(true);
            setError(null);
        } catch {
            setError('Camera not available; type the code instead.');
            setActive(false);
        }
    };

    useEffect(() => {
        if (autoStart && supported) {
            void start();
        }
        return stop;
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    useEffect(() => {
        if (!active || !window.BarcodeDetector) return;
        const detector = new window.BarcodeDetector({
            formats: ['qr_code', 'code_128', 'ean_13'],
        });
        let cancelled = false;

        const tick = async () => {
            if (cancelled) return;
            if (video.current && video.current.readyState >= 2 && !busy) {
                try {
                    const codes = await detector.detect(video.current);
                    const hit = codes[0]?.rawValue;
                    if (
                        hit &&
                        (hit !== last.current.code ||
                            Date.now() - last.current.at > 2500)
                    ) {
                        last.current = { code: hit, at: Date.now() };
                        onCode(hit);
                    }
                } catch {
                    // A frame that could not be read; try the next.
                }
            }
            window.setTimeout(() => void tick(), 250);
        };

        void tick();
        return () => {
            cancelled = true;
        };
    }, [active, busy, onCode]);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (typed.trim() === '') return;
        onCode(typed.trim());
        setTyped('');
    };

    return (
        <div className="space-y-3">
            {supported && (
                <div className="bg-muted relative overflow-hidden rounded-2xl border">
                    <video
                        ref={video}
                        className="aspect-[4/3] w-full object-cover"
                        muted
                        playsInline
                    />
                    {!active && (
                        <div className="text-muted-foreground absolute inset-0 flex flex-col items-center justify-center gap-2 text-sm">
                            <CameraOff className="size-6" />
                            {error ?? 'Camera off'}
                        </div>
                    )}
                    {active && (
                        <div className="pointer-events-none absolute inset-6 rounded-xl border-2 border-white/70" />
                    )}
                    <div className="absolute right-2 bottom-2">
                        <Button
                            type="button"
                            size="sm"
                            variant="secondary"
                            onClick={() => (active ? stop() : void start())}
                        >
                            {active ? (
                                <CameraOff className="size-4" />
                            ) : (
                                <Camera className="size-4" />
                            )}
                            {active ? 'Stop' : 'Camera'}
                        </Button>
                    </div>
                </div>
            )}
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
            {!supported && (
                <p className="text-muted-foreground text-xs">
                    This browser cannot read codes with the camera; type or
                    paste the code, or use a handheld scanner.
                </p>
            )}
        </div>
    );
}
