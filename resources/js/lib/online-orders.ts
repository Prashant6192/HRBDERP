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

    if (p.status === 'packed') {
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

/**
 * Put the chosen pages of the marketplace's own PDFs into one document, in
 * the order given, and open the print dialog. The originals are never
 * changed: this happens in the browser.
 */
export async function printPlan(plan: PrintPlan): Promise<void> {
    const { PDFDocument } = await import('pdf-lib');
    const out = await PDFDocument.create();
    const sources = new Map<
        number,
        Awaited<ReturnType<typeof PDFDocument.load>>
    >();

    for (const part of plan.parts) {
        let source = sources.get(part.file_id);

        if (!source) {
            const response = await fetch(plan.files[String(part.file_id)], {
                credentials: 'same-origin',
            });

            if (!response.ok) {
                throw new Error(
                    `A label file could not be fetched (${response.status}).`,
                );
            }

            source = await PDFDocument.load(await response.arrayBuffer(), {
                ignoreEncryption: true,
            });
            sources.set(part.file_id, source);
        }

        const indices = part.pages
            .map((p) => p - 1)
            .filter((i) => i >= 0 && i < source.getPageCount());
        const copied = await out.copyPages(source, indices);
        copied.forEach((page) => out.addPage(page));
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
