import type { PDFDocumentProxy } from 'pdfjs-dist';
import type { Box } from '@/lib/label-crop';
import type { DispatchTone } from '@/types';

export type ParcelLine = {
    id: number;
    seller_sku: string;
    description: string | null;
    quantity: number;
    mapped: boolean;
    item_id: number | null;
    item: string | null;
    item_code: string | null;
    units: string | null;
};

/** One product to take off the shelf for a parcel, in pieces. */
export type ParcelPick = {
    item_id: number;
    item: string | null;
    item_code: string | null;
    units: string;
    skus: string[];
};

export type ParcelStatus =
    | 'uploaded'
    | 'printed'
    | 'packed'
    | 'handed_over'
    | 'cancelled'
    | 'returned';

export type Parcel = {
    id: number;
    batch_id: number;
    file_id: number;
    pages: number[];
    awb: string | null;
    alt_code: string | null;
    order_number: string | null;
    courier: string | null;
    payment_mode: 'cod' | 'prepaid' | 'unknown';
    payment_label: string;
    payable_amount: string | null;
    invoice_number: string | null;
    customer_name: string | null;
    customer_state: string | null;
    status: ParcelStatus;
    status_label: string;
    status_tone: DispatchTone;
    stock_state:
        | 'unmapped'
        | 'short'
        | 'reserved'
        | 'consumed'
        | 'released'
        | 'returned'
        | null;
    stock_label: string | null;
    stock_tone: DispatchTone | null;
    print_count: number;
    printed_at: string | null;
    packed_at: string | null;
    packed_by: string | null;
    pack_method: string | null;
    pack_note: string | null;
    handed_over_at: string | null;
    handed_over_by?: string | null;
    cancel_reason: string | null;
    returned_at: string | null;
    warnings: string[];
    marketplace: string | null;
    brand: string | null;
    lines: ParcelLine[];
    picks: ParcelPick[];
    pictures?: { item_id: number; url: string }[];
};

export type Batch = {
    id: number;
    number: string;
    for_date: string;
    status: 'open' | 'closed';
    status_label: string;
    brand: string | null;
    brand_id: number;
    marketplace: string | null;
    marketplace_id: number;
    facility: string | null;
    store: string | null;
    store_code: string | null;
    uploaded_by: string | null;
    created_at: string | null;
    closed_at: string | null;
    closed_by: string | null;
};

export type Abilities = {
    upload: boolean;
    print: boolean;
    pack: boolean;
    handover: boolean;
    manage: boolean;
    restricted: boolean;
};

export type PrintPlan = {
    shipments: number;
    pages: number;
    parts: {
        shipment_id: number;
        file_id: number;
        pages: number[];
        courier: string | null;
        /** Meesho and Flipkart: cut to the label for a 4×6 printer. */
        crop?: boolean;
    }[];
    files: Record<string, string>;
};

/** Whether a parcel needs someone to look at it before it can be packed. */
export function needsAttention(p: Parcel): boolean {
    return (
        (p.status === 'uploaded' || p.status === 'printed') &&
        (p.awb === null ||
            p.stock_state === 'unmapped' ||
            p.stock_state === 'short')
    );
}

/**
 * Who may cancel a parcel from the screen. The agency cancels what the
 * marketplace cancelled before it is packed; the depot (print, pack) and
 * the office (manage) until the courier has it. After that it is a return.
 * The server checks the same.
 */
export function canCancel(can: Abilities, p: Parcel): boolean {
    if (p.status === 'uploaded' || p.status === 'printed') {
        return can.print || can.pack || can.manage;
    }

    // Packed, or scanned out but still on the courier's pile: the depot can
    // still cancel it and put the goods back.
    if (p.status === 'packed' || p.status === 'handed_over') {
        return can.print || can.pack || can.manage;
    }

    return false;
}

export function courierName(courier: string | null): string {
    return courier ?? 'Courier not read';
}

/**
 * What goes in the parcel, in one line: "Rahat Rooh 500 ml × 2 + Satreetha
 * × 1", or the SKUs as printed while they are not mapped yet.
 */
export function describeParcel(p: Parcel): string {
    if (p.picks.length > 0) {
        return p.picks
            .map((pick) => `${pick.item ?? '?'} × ${Number(pick.units)}`)
            .join(' + ');
    }

    return p.lines.map((l) => `${l.seller_sku} × ${l.quantity}`).join(', ');
}

/** Pieces in the whole parcel, across its products. */
export function pieceCount(p: Parcel): number {
    return p.picks.reduce((n, pick) => n + Number(pick.units), 0);
}

/** Where the print size choice is remembered, on this computer only. */
const FULL_PAGE_KEY = 'hrbd.print.full-page';

/**
 * Whether Meesho and Flipkart labels print as the whole page with the tax
 * invoice instead of cut to 4×6. Remembered on this computer.
 */
export function printsFullPage(): boolean {
    try {
        return window.localStorage.getItem(FULL_PAGE_KEY) === '1';
    } catch {
        return false;
    }
}

export function rememberFullPage(full: boolean): void {
    try {
        window.localStorage.setItem(FULL_PAGE_KEY, full ? '1' : '0');
    } catch {
        // Not remembered; the choice still holds for this print.
    }
}

/** Labels already measured in this tab: file URL and page to the label. */
const measured = new Map<string, Box | null>();

export type PrintOptions = {
    /** Print Meesho and Flipkart pages whole, with the invoice. */
    fullPage?: boolean;
    onProgress?: (done: number, total: number) => void;
};

/**
 * Put the chosen pages of the marketplace's own PDFs into one document, in
 * the order given, and open the print dialog. Meesho and Flipkart pages
 * are cut to their label on a 4×6 inch page unless the whole page is
 * asked for. The originals are never changed: this happens in the
 * browser.
 */
export async function printPlan(
    plan: PrintPlan,
    options: PrintOptions = {},
): Promise<void> {
    const [{ PDFDocument, degrees }, crop] = await Promise.all([
        import('pdf-lib'),
        import('@/lib/label-crop'),
    ]);
    const cutting = !options.fullPage && plan.parts.some((p) => p.crop);
    const lib = cutting ? await import('@/lib/label-pictures') : null;
    const out = await PDFDocument.create();
    const sources = new Map<
        number,
        {
            doc: Awaited<ReturnType<typeof PDFDocument.load>>;
            bytes: ArrayBuffer;
            seen: PDFDocumentProxy | null;
        }
    >();
    const total = plan.parts.reduce((n, p) => n + p.pages.length, 0);
    let done = 0;

    try {
        for (const part of plan.parts) {
            const url = plan.files[String(part.file_id)];
            let source = sources.get(part.file_id);

            if (!source) {
                const response = await fetch(url, {
                    credentials: 'same-origin',
                });

                if (!response.ok) {
                    throw new Error(
                        `A label file could not be fetched (${response.status}).`,
                    );
                }

                const bytes = await response.arrayBuffer();
                source = {
                    doc: await PDFDocument.load(bytes, {
                        ignoreEncryption: true,
                    }),
                    bytes,
                    seen: null,
                };
                sources.set(part.file_id, source);
            }

            const indices = part.pages
                .map((p) => p - 1)
                .filter((i) => i >= 0 && i < source.doc.getPageCount());

            for (const index of indices) {
                let box: Box | null = null;

                if (cutting && part.crop && lib) {
                    const key = `${url}#${index}`;

                    if (measured.has(key)) {
                        box = measured.get(key) ?? null;
                    } else {
                        try {
                            // pdf.js takes the bytes it is given: hand it a copy.
                            source.seen ??= await (
                                await lib.pdfjs()
                            ).getDocument({
                                data: new Uint8Array(source.bytes.slice(0)),
                            }).promise;
                            const page = await source.seen.getPage(index + 1);
                            box = await crop.findLabel(page);
                            page.cleanup();
                        } catch {
                            box = null;
                        }

                        measured.set(key, box);
                    }
                }

                if (box) {
                    const [label] = await out.embedPages(
                        [source.doc.getPage(index)],
                        [box],
                    );
                    const at = crop.placeOnLabel(box);
                    out.addPage([crop.LABEL_WIDTH, crop.LABEL_HEIGHT]).drawPage(
                        label,
                        {
                            x: at.x,
                            y: at.y,
                            width: (box.right - box.left) * at.scale,
                            height: (box.top - box.bottom) * at.scale,
                            rotate: degrees(at.sideways ? 90 : 0),
                        },
                    );
                } else {
                    const [copied] = await out.copyPages(source.doc, [index]);
                    out.addPage(copied);
                }

                options.onProgress?.(++done, total);
            }
        }
    } finally {
        for (const s of sources.values()) {
            await s.seen?.destroy();
        }
    }

    const bytes = await out.save();
    const url = URL.createObjectURL(
        new Blob([bytes as BlobPart], { type: 'application/pdf' }),
    );

    // A hidden frame prints without leaving the page; where the browser will
    // not print from a frame, the PDF opens in a tab instead.
    const frame = document.createElement('iframe');
    frame.style.position = 'fixed';
    frame.style.right = '0';
    frame.style.bottom = '0';
    frame.style.width = '0';
    frame.style.height = '0';
    frame.style.border = '0';
    frame.src = url;
    document.body.appendChild(frame);

    frame.onload = () => {
        try {
            frame.contentWindow?.focus();
            frame.contentWindow?.print();
        } catch {
            window.open(url, '_blank');
        }

        setTimeout(() => {
            frame.remove();
            URL.revokeObjectURL(url);
        }, 60_000);
    };
}

/**
 * POST JSON to the ERP with the session's CSRF token, and read the JSON
 * back — whatever the status, so the screen can say what went wrong.
 */
export async function postJson<T>(
    url: string,
    body: unknown,
): Promise<{ ok: boolean; status: number; data: T }> {
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': decodeURIComponent(
                document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/)?.[1] ?? '',
            ),
        },
        body: JSON.stringify(body),
    });

    let data: T;

    try {
        data = (await response.json()) as T;
    } catch {
        data = {
            message: `The server answered ${response.status}.`,
        } as unknown as T;
    }

    return { ok: response.ok, status: response.status, data };
}
