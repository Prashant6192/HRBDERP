import { Camera, CameraOff, Loader2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { barcodeDetector, cameraAvailable } from '@/lib/barcode';
import { cn } from '@/lib/utils';

type State = 'off' | 'starting' | 'on' | 'error';

/**
 * The phone's (or laptop's) camera, reading barcodes and QR codes. Calls
 * `onCode` once per code; the same code again only after a pause, so a
 * label held still is not read twenty times.
 */
export function BarcodeCamera({
    onCode,
    busy = false,
    autoStart = true,
    onClose,
    className,
}: {
    onCode: (code: string) => void;
    busy?: boolean;
    autoStart?: boolean;
    onClose?: () => void;
    className?: string;
}) {
    const video = useRef<HTMLVideoElement | null>(null);
    const stream = useRef<MediaStream | null>(null);
    const [state, setState] = useState<State>('off');
    const [error, setError] = useState<string | null>(null);
    const last = useRef<{ code: string; at: number }>({ code: '', at: 0 });
    const handler = useRef(onCode);
    const busyRef = useRef(busy);

    useEffect(() => {
        handler.current = onCode;
        busyRef.current = busy;
    }, [onCode, busy]);

    const stop = () => {
        stream.current?.getTracks().forEach((t) => t.stop());
        stream.current = null;
        setState('off');
    };

    const start = async () => {
        setState('starting');
        setError(null);

        try {
            const media = await navigator.mediaDevices.getUserMedia({
                video: {
                    facingMode: { ideal: 'environment' },
                    width: { ideal: 1280 },
                    height: { ideal: 720 },
                },
                audio: false,
            });
            stream.current = media;

            if (video.current) {
                video.current.srcObject = media;
                await video.current.play();
            }

            setState('on');
        } catch (e) {
            stop();
            setState('error');
            setError(
                e instanceof DOMException && e.name === 'NotAllowedError'
                    ? 'Camera permission was refused. Allow the camera for this site in the browser settings, or type the code.'
                    : 'No camera could be opened. Type the code, or use a handheld scanner.',
            );
        }
    };

    useEffect(() => {
        if (autoStart && cameraAvailable()) {
            void start();
        }

        return () => {
            stream.current?.getTracks().forEach((t) => t.stop());
            stream.current = null;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    useEffect(() => {
        if (state !== 'on') {
            return;
        }

        let cancelled = false;
        let timer = 0;

        const run = async () => {
            let detector;

            try {
                detector = await barcodeDetector();
            } catch {
                if (!cancelled) {
                    setError(
                        'The barcode reader could not load. Type the code instead.',
                    );
                    stop();
                    setState('error');
                }

                return;
            }

            const tick = async () => {
                if (cancelled) {
                    return;
                }

                const v = video.current;

                if (v && v.readyState >= 2 && !busyRef.current) {
                    try {
                        const codes = await detector.detect(v);
                        const now = Date.now();
                        const hit = codes
                            .map((c) => c.rawValue.trim())
                            .find(
                                (c) =>
                                    c !== '' &&
                                    (c !== last.current.code ||
                                        now - last.current.at > 2500),
                            );

                        if (hit && !cancelled) {
                            last.current = { code: hit, at: now };
                            if (navigator.userActivation?.hasBeenActive) {
                                navigator.vibrate?.(40);
                            }
                            handler.current(hit);
                        }
                    } catch {
                        // A frame that could not be read; try the next.
                    }
                }

                timer = window.setTimeout(() => void tick(), 200);
            };

            void tick();
        };

        void run();

        return () => {
            cancelled = true;
            window.clearTimeout(timer);
        };
    }, [state]);

    if (!cameraAvailable()) {
        return (
            <p className="text-muted-foreground text-xs">
                This browser cannot open a camera here (it needs a secure https
                address). Type or paste the code, or use a handheld scanner.
            </p>
        );
    }

    return (
        <div
            className={cn(
                'relative overflow-hidden rounded-2xl border bg-black',
                className,
            )}
        >
            <video
                ref={video}
                className={cn(
                    'aspect-[4/3] w-full object-cover',
                    state !== 'on' && 'invisible',
                )}
                muted
                playsInline
            />
            {state !== 'on' && (
                <div className="absolute inset-0 flex flex-col items-center justify-center gap-2 p-4 text-center text-sm text-white/80">
                    {state === 'starting' ? (
                        <>
                            <Loader2 className="size-6 animate-spin" />
                            Opening the camera…
                        </>
                    ) : (
                        <>
                            <CameraOff className="size-6" />
                            {error ?? 'Camera off'}
                        </>
                    )}
                </div>
            )}
            {state === 'on' && (
                <>
                    <div className="pointer-events-none absolute inset-x-6 top-1/2 h-24 -translate-y-1/2 rounded-xl border-2 border-white/80 shadow-[0_0_0_9999px_rgba(0,0,0,0.25)]" />
                    <div className="pointer-events-none absolute inset-x-8 top-1/2 h-0.5 -translate-y-1/2 animate-pulse bg-red-500/80" />
                    <p className="absolute inset-x-0 top-3 text-center text-xs font-medium text-white drop-shadow">
                        Hold the barcode inside the box
                    </p>
                </>
            )}
            <div className="absolute right-2 bottom-2 flex gap-2">
                {onClose && (
                    <Button
                        type="button"
                        size="sm"
                        variant="secondary"
                        onClick={() => {
                            stop();
                            onClose();
                        }}
                    >
                        Close
                    </Button>
                )}
                {!onClose && (
                    <Button
                        type="button"
                        size="sm"
                        variant="secondary"
                        onClick={() =>
                            state === 'on' || state === 'starting'
                                ? stop()
                                : void start()
                        }
                    >
                        {state === 'on' ? (
                            <CameraOff className="size-4" />
                        ) : (
                            <Camera className="size-4" />
                        )}
                        {state === 'on' ? 'Stop' : 'Camera'}
                    </Button>
                )}
            </div>
        </div>
    );
}
