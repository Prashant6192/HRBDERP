/**
 * Reading label PDFs whose pages are pictures (Myntra's labels and tax
 * invoices) on the uploader's own computer, before the upload: pdf.js
 * draws each page, the barcode reader finds its codes, and tesseract
 * reads its words. Nothing leaves the computer but the text it read, sent
 * with the PDF for the ERP's Myntra parser. No AI service, no key.
 *
 * Every piece (pdf.js, tesseract and its English data) is served by the
 * ERP itself and loaded only when a Myntra file is uploaded.
 */
import type { Worker as OcrWorker } from 'tesseract.js';
import { barcodeDetector } from '@/lib/barcode';

export type SeenPage = {
    page: number;
    text: string;
    codes: { format: string; text: string }[];
};

export type ReadingProgress = {
    file: string;
    page: number;
    pages: number;
};

/** Pages with more text than this are read by the server itself. */
const TEXT_PAGE = 20;

/** Pages are drawn about this wide: 150 dpi on an A4 or letter page. */
const DRAW_WIDTH = 1300;

let ocr: Promise<OcrWorker> | null = null;

const absolute = (url: string) => new URL(url, window.location.href).href;

/** pdf.js with its worker, loaded the first time it is needed. */
export async function pdfjs() {
    const [lib, PdfWorker] = await Promise.all([
        import('pdfjs-dist'),
        import('pdfjs-dist/build/pdf.worker.min.mjs?worker'),
    ]);

    // Bundled as a plain .js worker: servers do not all serve .mjs as
    // JavaScript.
    lib.GlobalWorkerOptions.workerPort ??= new PdfWorker.default();

    return lib;
}

function ocrWorker(): Promise<OcrWorker> {
    ocr ??= (async () => {
        const [{ createWorker, OEM }, worker, core, eng] = await Promise.all([
            import('tesseract.js'),
            import('tesseract.js/dist/worker.min.js?url'),
            import('tesseract.js-core/tesseract-core-simd-lstm.wasm.js?url'),
            import('@tesseract.js-data/eng/4.0.0_best_int/eng.traineddata.gz?url'),
        ]);

        // The build keeps the language file's name, so its folder is the
        // language path.
        return createWorker('eng', OEM.LSTM_ONLY, {
            workerPath: absolute(worker.default),
            corePath: absolute(core.default),
            langPath: new URL('.', absolute(eng.default)).href.replace(
                /\/$/,
                '',
            ),
        });
    })().catch((e: unknown) => {
        ocr = null;

        throw e;
    });

    return ocr;
}

async function codesOn(canvas: HTMLCanvasElement): Promise<SeenPage['codes']> {
    try {
        const detector = await barcodeDetector();
        const found = (await detector.detect(canvas)) as {
            rawValue: string;
            format?: string;
        }[];

        return found
            .filter((c) => c.rawValue.trim() !== '')
            .map((c) => ({ format: c.format ?? 'unknown', text: c.rawValue }));
    } catch {
        return [];
    }
}

/**
 * Read every picture page of a PDF. Pages that carry text are left out:
 * the server reads those exactly.
 */
export async function readPicturePages(
    file: File,
    onProgress?: (progress: ReadingProgress) => void,
): Promise<SeenPage[]> {
    const lib = await pdfjs();
    const task = lib.getDocument({
        data: new Uint8Array(await file.arrayBuffer()),
    });
    const doc = await task.promise;
    const seen: SeenPage[] = [];

    try {
        for (let n = 1; n <= doc.numPages; n++) {
            onProgress?.({ file: file.name, page: n, pages: doc.numPages });

            const page = await doc.getPage(n);
            const content = await page.getTextContent();
            const chars = content.items.reduce(
                (sum, item) =>
                    sum + ('str' in item ? item.str.trim().length : 0),
                0,
            );

            if (chars > TEXT_PAGE) {
                page.cleanup();
                continue;
            }

            const natural = page.getViewport({ scale: 1 });
            const viewport = page.getViewport({
                scale: Math.min(4, DRAW_WIDTH / natural.width),
            });
            const canvas = document.createElement('canvas');
            canvas.width = Math.ceil(viewport.width);
            canvas.height = Math.ceil(viewport.height);

            const context = canvas.getContext('2d');

            if (!context) {
                throw new Error('This browser cannot draw the label pages.');
            }

            await page.render({ canvasContext: context, viewport }).promise;

            const codes = await codesOn(canvas);
            const { data } = await (await ocrWorker()).recognize(canvas);

            seen.push({ page: n, text: data.text, codes });
            page.cleanup();
            canvas.width = 0;
            canvas.height = 0;
        }
    } finally {
        await task.destroy();
    }

    return seen;
}

/** The file's SHA-256, as the server names it. */
export async function fileHash(file: File): Promise<string> {
    const digest = await crypto.subtle.digest(
        'SHA-256',
        await file.arrayBuffer(),
    );

    return Array.from(new Uint8Array(digest))
        .map((b) => b.toString(16).padStart(2, '0'))
        .join('');
}
