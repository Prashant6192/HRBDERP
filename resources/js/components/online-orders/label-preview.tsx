import { Loader2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

/**
 * One page of a marketplace's label file, drawn in the page: so the label
 * a parcel will carry can be read without leaving the screen.
 */
export function LabelPreview({
    fileUrl,
    page,
}: {
    fileUrl: string;
    page: number;
}) {
    const canvas = useRef<HTMLCanvasElement | null>(null);
    const [state, setState] = useState<'loading' | 'shown' | 'failed'>(
        'loading',
    );

    useEffect(() => {
        let cancelled = false;
        let close: (() => void) | undefined;
        setState('loading');

        void (async () => {
            try {
                const lib = await (
                    await import('@/lib/label-pictures')
                ).pdfjs();
                const doc = await lib.getDocument({
                    url: fileUrl,
                    withCredentials: true,
                }).promise;
                close = () => void doc.destroy();

                const sheet = await doc.getPage(
                    Math.min(Math.max(1, page), doc.numPages),
                );
                const view = sheet.getViewport({ scale: 2 });
                const el = canvas.current;

                if (cancelled || !el) {
                    return;
                }

                el.width = view.width;
                el.height = view.height;
                await sheet.render({
                    canvasContext: el.getContext('2d')!,
                    viewport: view,
                }).promise;

                if (!cancelled) {
                    setState('shown');
                }
            } catch {
                if (!cancelled) {
                    setState('failed');
                }
            }
        })();

        return () => {
            cancelled = true;
            close?.();
        };
    }, [fileUrl, page]);

    return (
        <div className="bg-background relative overflow-hidden rounded-xl border">
            {state === 'loading' && (
                <p className="text-muted-foreground flex items-center gap-2 p-4 text-sm">
                    <Loader2 className="size-4 animate-spin" />
                    Opening the label…
                </p>
            )}
            {state === 'failed' && (
                <p className="p-4 text-sm">
                    The label could not be shown here.{' '}
                    <a
                        href={`${fileUrl}#page=${page}`}
                        target="_blank"
                        rel="noreferrer"
                        className="text-primary font-medium underline"
                    >
                        Open the file
                    </a>{' '}
                    and go to page {page}.
                </p>
            )}
            <canvas
                ref={canvas}
                className={state === 'shown' ? 'block h-auto w-full' : 'hidden'}
            />
        </div>
    );
}
