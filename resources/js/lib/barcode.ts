/**
 * Reading barcodes with the camera. Chrome on Android has a reader built
 * in; everywhere else (iPhone Safari, Firefox, Chrome on Windows) the
 * zxing reader bundled with the ERP does the same job, loaded only the
 * first time a camera is opened.
 */

export type Detector = {
    detect: (source: ImageBitmapSource) => Promise<{ rawValue: string }[]>;
};

type DetectorClass = {
    new (options?: { formats?: string[] }): Detector;
    getSupportedFormats?: () => Promise<string[]>;
};

declare global {
    interface Window {
        BarcodeDetector?: DetectorClass;
    }
}

/**
 * Everything printed on a marketplace label, a batch sticker or a rack:
 * AWBs are Code 128 (sometimes Code 39), Amazon adds a Data Matrix or
 * PDF417, the ERP's own stickers are QR codes.
 */
export const LABEL_FORMATS = [
    'code_128',
    'code_39',
    'code_93',
    'codabar',
    'itf',
    'ean_13',
    'ean_8',
    'upc_a',
    'upc_e',
    'qr_code',
    'data_matrix',
    'pdf417',
];

/**
 * The barcodes printed in lines: every AWB, order and product barcode.
 * A packing table reads only these, so the square codes a label also
 * carries (routing codes, not the parcel's own) are never taken for it.
 */
export const LINE_FORMATS = LABEL_FORMATS.filter(
    (f) => !['qr_code', 'data_matrix', 'pdf417'].includes(f),
);

const pending = new Map<string, Promise<Detector>>();

async function nativeDetector(wanted: string[]): Promise<Detector | null> {
    const Native =
        typeof window !== 'undefined' ? window.BarcodeDetector : undefined;

    if (!Native) {
        return null;
    }

    try {
        const supported = (await Native.getSupportedFormats?.()) ?? [];
        const formats = wanted.filter((f) => supported.includes(f));

        // A built-in reader that cannot read Code 128 cannot read an AWB.
        return formats.includes('code_128') ? new Native({ formats }) : null;
    } catch {
        return null;
    }
}

async function bundledDetector(wanted: string[]): Promise<Detector> {
    const [{ BarcodeDetector, prepareZXingModule }, wasm] = await Promise.all([
        import('barcode-detector/ponyfill'),
        import('zxing-wasm/reader/zxing_reader.wasm?url'),
    ]);

    // Serve the reader from the ERP itself, not from a CDN.
    prepareZXingModule({
        overrides: {
            locateFile: (path: string, prefix: string) =>
                path.endsWith('.wasm') ? wasm.default : prefix + path,
        },
    });

    return new BarcodeDetector({
        formats: wanted as NonNullable<
            ConstructorParameters<typeof BarcodeDetector>[0]
        >['formats'],
    });
}

/**
 * One reader for the page for each set of formats, made on first use.
 */
export function barcodeDetector(
    formats: string[] = LABEL_FORMATS,
): Promise<Detector> {
    const key = formats.join(',');
    let made = pending.get(key);

    if (!made) {
        made = nativeDetector(formats)
            .then((native) => native ?? bundledDetector(formats))
            .catch((error: unknown) => {
                pending.delete(key);

                throw error;
            });
        pending.set(key, made);
    }

    return made;
}

/**
 * Whether this browser can open a camera at all. Needs HTTPS (or
 * localhost).
 */
export function cameraAvailable(): boolean {
    return (
        typeof navigator !== 'undefined' &&
        typeof navigator.mediaDevices?.getUserMedia === 'function'
    );
}
