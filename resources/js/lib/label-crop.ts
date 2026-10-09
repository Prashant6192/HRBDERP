/**
 * Meesho and Flipkart print the courier label and the tax invoice on one
 * A4 page, which a 4×6 inch label printer cannot take. For printing, each
 * such page is cut to its label (everything above "Tax Invoice", trimmed
 * to the label's own border) and laid on a 4×6 inch page, turned sideways
 * when the label is wider than it is tall. The PDFs on the server are
 * never changed, and a page where no "Tax Invoice" is found prints whole.
 *
 * Where the label ends is found from the page itself: pdf.js gives the
 * position of the words "Tax Invoice", and the page drawn above them
 * shows the border or the dashed cut line that closes the label.
 */
import type { PDFPageProxy } from 'pdfjs-dist';
import { pdfjs } from '@/lib/label-pictures';

/** A rectangle on the source page, in PDF points from its bottom left. */
export type Box = { left: number; bottom: number; right: number; top: number };

/** A 4×6 inch label, in points. */
export const LABEL_WIDTH = 288;
export const LABEL_HEIGHT = 432;

/** Space left round the label on the 4×6 page, in points (about 2 mm). */
const MARGIN = 6;

/** Pixels per point the page is drawn at to find the label's edges. */
const SCALE = 2;

/** Darker than this is a line or text; lighter than PAPER is blank paper. */
const INK = 150;
const PAPER = 235;

const TAX = /tax/i;
const TAX_INVOICE = /tax\s*invoice/i;
const INVOICE = /^\s*invoice/i;

type TextBit = { str: string; transform: number[]; height: number };

const luminance = (d: Uint8ClampedArray, i: number) =>
    0.299 * d[i] + 0.587 * d[i + 1] + 0.114 * d[i + 2];

/**
 * Where, in drawn pixels from the top of the page, the words "Tax Invoice"
 * begin: the topmost time they appear. Null when the page has no such
 * words.
 */
async function invoiceTop(
    page: PDFPageProxy,
    toViewport: number[],
): Promise<number | null> {
    const { Util } = await pdfjs();
    const content = await page.getTextContent();
    const bits = content.items.filter(
        (item): item is TextBit & typeof item => 'str' in item,
    ) as unknown as TextBit[];
    let top: number | null = null;

    bits.forEach((bit, i) => {
        const next = bits[i + 1];
        const sameLine =
            next !== undefined &&
            Math.abs(next.transform[5] - bit.transform[5]) < 2;
        const found =
            TAX_INVOICE.test(bit.str) ||
            (TAX.test(bit.str) &&
                /tax\s*$/i.test(bit.str) &&
                sameLine &&
                INVOICE.test(next.str));

        if (!found) {
            return;
        }

        const at = Util.transform(toViewport, bit.transform);
        const size = Math.hypot(at[2], at[3]) || bit.height * SCALE;
        // Capitals stand about 0.7 of the size above the baseline.
        const y = at[5] - size * 0.8;

        if (top === null || y < top) {
            top = y;
        }
    });

    return top;
}

/**
 * The line that closes the label just above "Tax Invoice": a solid border
 * belongs to the label and is kept; a dashed cut line is not. Returns the
 * first pixel row below the label.
 */
function labelBottom(
    data: Uint8ClampedArray,
    width: number,
    from: number,
): number {
    const look = Math.min(from, 80 * SCALE);
    const darkness = (row: number) => {
        let dark = 0;
        let run = 0;
        let longest = 0;

        for (let x = 0; x < width; x++) {
            if (luminance(data, (row * width + x) * 4) < INK) {
                dark++;
                run++;
                longest = Math.max(longest, run);
            } else {
                run = 0;
            }
        }

        return { dark, longest };
    };

    for (let row = from - 1; row >= from - look && row >= 0; row--) {
        const { dark, longest } = darkness(row);

        if (dark < width * 0.25) {
            continue;
        }

        // The line may be a few pixels thick: find its top.
        let top = row;

        while (top > 0 && darkness(top - 1).dark >= width * 0.25) {
            top--;
        }

        return longest >= dark * 0.6 ? row + 1 : top;
    }

    // Nothing drawn between: stop just above the words.
    return Math.max(0, from - Math.round(2 * SCALE));
}

/**
 * The label's left and right edge, in pixels: its longest border line,
 * when what is drawn outside that line is only a stray mark (a pair of
 * scissors by the cut line, a page number). Otherwise the whole width.
 */
function labelSides(
    data: Uint8ClampedArray,
    width: number,
    bottom: number,
): [number, number] {
    let start = 0;
    let length = 0;

    for (let y = 0; y < bottom; y++) {
        let run = 0;

        for (let x = 0; x < width; x++) {
            if (luminance(data, (y * width + x) * 4) < INK) {
                run++;

                if (run > length) {
                    length = run;
                    start = x - run + 1;
                }
            } else {
                run = 0;
            }
        }
    }

    if (length < width * 0.3) {
        return [0, width - 1];
    }

    const end = start + length - 1;
    let inside = 0;
    let outside = 0;

    for (let y = 0; y < bottom; y++) {
        for (let x = 0; x < width; x++) {
            if (luminance(data, (y * width + x) * 4) < PAPER) {
                if (x >= start - 2 && x <= end + 2) {
                    inside++;
                } else {
                    outside++;
                }
            }
        }
    }

    return outside <= inside * 0.03
        ? [Math.max(0, start - 2), Math.min(width - 1, end + 2)]
        : [0, width - 1];
}

/**
 * The label on one page of a Meesho or Flipkart PDF, or null when the page
 * should print whole.
 */
export async function findLabel(page: PDFPageProxy): Promise<Box | null> {
    // A turned page would come out turned again: print it as it is.
    if (page.rotate % 360 !== 0) {
        return null;
    }

    const viewport = page.getViewport({ scale: SCALE });
    const textTop = await invoiceTop(page, viewport.transform);

    // The words must sit below a label worth printing.
    if (textTop === null || textTop < viewport.height * 0.1) {
        return null;
    }

    const width = Math.ceil(viewport.width);
    const height = Math.ceil(textTop);
    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;
    const context = canvas.getContext('2d', { willReadFrequently: true });

    if (!context) {
        return null;
    }

    context.fillStyle = '#fff';
    context.fillRect(0, 0, width, height);

    try {
        await page.render({ canvasContext: context, viewport }).promise;

        const { data } = context.getImageData(0, 0, width, height);
        const bottom = labelBottom(data, width, height);
        const [fromX, toX] = labelSides(data, width, bottom);
        let minX = width;
        let maxX = -1;
        let minY = bottom;
        let maxY = -1;

        for (let y = 0; y < bottom; y++) {
            for (let x = fromX; x <= toX; x++) {
                if (luminance(data, (y * width + x) * 4) < PAPER) {
                    minX = Math.min(minX, x);
                    maxX = Math.max(maxX, x);
                    minY = Math.min(minY, y);
                    maxY = Math.max(maxY, y);
                }
            }
        }

        if (maxX < 0 || maxY - minY < viewport.height * 0.08) {
            return null;
        }

        const pad = 1.5 * SCALE;
        const [x1, y1] = viewport.convertToPdfPoint(
            Math.max(0, minX - pad),
            Math.max(0, minY - pad),
        );
        const [x2, y2] = viewport.convertToPdfPoint(
            Math.min(width, maxX + 1 + pad),
            Math.min(bottom, maxY + 1 + pad),
        );

        return {
            left: Math.min(x1, x2),
            right: Math.max(x1, x2),
            bottom: Math.min(y1, y2),
            top: Math.max(y1, y2),
        };
    } finally {
        canvas.width = 0;
        canvas.height = 0;
    }
}

/**
 * Where a label of the given size goes on a 4×6 page: its scale, whether
 * it is turned a quarter to the left (when wider than tall), and where the
 * turned or upright label's own bottom-left corner lands.
 */
export function placeOnLabel(box: Box): {
    scale: number;
    sideways: boolean;
    x: number;
    y: number;
} {
    const w = box.right - box.left;
    const h = box.top - box.bottom;
    const sideways = w > h;
    const across = sideways ? h : w;
    const up = sideways ? w : h;
    const scale = Math.min(
        (LABEL_WIDTH - 2 * MARGIN) / across,
        (LABEL_HEIGHT - 2 * MARGIN) / up,
    );
    const left = (LABEL_WIDTH - across * scale) / 2;
    const bottom = (LABEL_HEIGHT - up * scale) / 2;

    // Turned a quarter to the left, the label's bottom-left corner sits at
    // the bottom right of the space it takes.
    return sideways
        ? { scale, sideways, x: left + across * scale, y: bottom }
        : { scale, sideways, x: left, y: bottom };
}
