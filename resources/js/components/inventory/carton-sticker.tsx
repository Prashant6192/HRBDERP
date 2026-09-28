import {
    Bluetooth,
    FileText,
    Printer,
    RefreshCw,
    Usb,
    type LucideIcon,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    pairedUsbPrinters,
    requestBluetoothPrinter,
    requestUsbPrinter,
    sendBluetooth,
    sendUsb,
    supportsBluetooth,
    supportsUsb,
    usbName,
    type BleDeviceLike,
    type UsbDeviceLike,
} from '@/lib/label-printer';
import { cn } from '@/lib/utils';
import cartons from '@/routes/lots/cartons';

export type Sticker = {
    brand: string;
    product: string;
    code: string;
    units: number;
    net_per_unit: string | null;
    net_carton: string | null;
    batch: string;
    mfg: string | null;
    expiry: string | null;
    mrp_unit: string | null;
    mrp_carton: string | null;
    gross_weight: string | null;
    licence: string | null;
    manufacturer: string;
    marketed_by: string | null;
    consumer_care: string | null;
    barcode: string | null;
    remarks: string | null;
    first_box: number;
    last_box: number;
    sample: { no: number; code: string; url: string } | null;
};

export type PrintRow = {
    id: number;
    format: string;
    printer: string | null;
    first_box: number;
    last_box: number;
    copies: number;
    by: string | null;
    at: string;
};

/**
 * The sticker as it will print, at its real proportions (100 × 150 mm),
 * with the first box shown. Every line says where it came from on hover.
 */
export function StickerPreview({ sticker: s }: { sticker: Sticker }) {
    const box = s.sample?.no ?? s.first_box;
    const Row = ({
        k,
        v,
        from,
    }: {
        k: string;
        v: string | null;
        from: string;
    }) => (
        <div className="flex gap-3" title={`From ${from}`}>
            <span className="w-[5.5rem] shrink-0 text-neutral-600">{k}</span>
            <span className="font-semibold">{v ?? '—'}</span>
        </div>
    );

    return (
        <figure className="flex flex-col items-center gap-2">
            <div
                aria-label="Carton sticker preview"
                className="flex aspect-[2/3] w-[300px] max-w-full flex-col gap-1.5 border border-neutral-400 bg-white p-4 text-[11px] leading-snug text-neutral-950 shadow-sm"
            >
                <div className="text-[13px] font-bold tracking-wide uppercase">
                    {s.brand}
                </div>
                <div className="text-[16px] leading-tight font-bold">
                    {s.product}
                </div>
                <div className="text-[12px] font-semibold">
                    {s.units} pcs{s.net_per_unit ? ` × ${s.net_per_unit}` : ''}
                    {s.net_carton ? ` · Net ${s.net_carton}` : ''}
                </div>
                <hr className="my-1 border-neutral-500" />
                <Row k="Batch No." v={s.batch} from="the batch" />
                <Row k="Mfg." v={s.mfg} from="the batch" />
                <Row k="Use before" v={s.expiry} from="the batch" />
                <Row k="Gross wt." v={s.gross_weight} from="the carton plan" />
                <Row k="Lic. No." v={s.licence} from="the factory's record" />
                <div className="mt-1 grid grid-cols-2 gap-1.5">
                    {[
                        ['MRP per piece', s.mrp_unit],
                        [`MRP of carton, ${s.units} pcs`, s.mrp_carton],
                    ].map(([label, value]) => (
                        <div
                            key={label}
                            className="rounded-md border-[1.5px] border-neutral-900 px-2 py-1"
                        >
                            <div className="text-[8.5px] leading-tight text-neutral-700">
                                {label} (incl. of all taxes)
                            </div>
                            <div className="text-[15px] font-bold">
                                {value ? `₹ ${value}` : '—'}
                            </div>
                        </div>
                    ))}
                </div>
                <p className="text-[8.5px] leading-tight text-neutral-800">
                    Mfd. by: {s.manufacturer}
                </p>
                {s.marketed_by && (
                    <p className="text-[8.5px] leading-tight text-neutral-800">
                        {s.marketed_by}
                    </p>
                )}
                {s.consumer_care && (
                    <p className="text-[8.5px] leading-tight text-neutral-800">
                        Consumer care: {s.consumer_care}
                    </p>
                )}
                <div className="mt-auto flex items-end gap-3">
                    <div
                        aria-hidden
                        className="size-[76px] shrink-0 border-[3px] border-white bg-[repeating-conic-gradient(#111_0_25%,#fff_0_50%)] bg-[length:9px_9px] outline outline-1 outline-neutral-900"
                    />
                    <div>
                        <div className="text-[9px] tracking-widest">CARTON</div>
                        <div className="text-[26px] leading-none font-bold">
                            {String(box).padStart(2, '0')}{' '}
                            <span className="text-[12px] font-semibold text-neutral-600">
                                of {s.last_box}
                            </span>
                        </div>
                        <div className="mt-0.5 font-mono text-[8px] text-neutral-700">
                            {s.sample?.code}
                        </div>
                    </div>
                </div>
            </div>
            <figcaption className="text-muted-foreground text-xs">
                100 × 150 mm · the QR carries batch, box and pieces
            </figcaption>
        </figure>
    );
}

type Choice =
    | { kind: 'usb'; device: UsbDeviceLike; name: string }
    | { kind: 'bluetooth'; device: BleDeviceLike; name: string }
    | { kind: 'sticker_pdf'; name: string }
    | { kind: 'a5'; name: string };

const CHOICE_KEY = 'erp.labelPrinter';

function readChoice(): string | null {
    try {
        return window.localStorage.getItem(CHOICE_KEY);
    } catch {
        return null;
    }
}

function writeChoice(name: string): void {
    try {
        window.localStorage.setItem(CHOICE_KEY, name);
    } catch {
        // Not remembered in a private window.
    }
}

const xsrf = (): string => {
    const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);

    return m ? decodeURIComponent(m[1]) : '';
};

/**
 * Where the stickers go: a TSC on this computer's USB port or over
 * Bluetooth, straight from the browser; or a PDF, 100 × 150 mm for the
 * TSC's own driver or A5 sheets for the office printer.
 */
export function CartonPrintPanel({
    lotId,
    sticker,
    prints,
}: {
    lotId: number;
    sticker: Sticker;
    prints: PrintRow[];
}) {
    const [choices, setChoices] = useState<Choice[]>([
        { kind: 'sticker_pdf', name: '100 × 150 PDF (TSC driver)' },
        { kind: 'a5', name: 'A5 sheets (office printer)' },
    ]);
    const [chosen, setChosen] = useState<string>(
        () => readChoice() ?? '100 × 150 PDF (TSC driver)',
    );
    const [from, setFrom] = useState(String(sticker.first_box));
    const [to, setTo] = useState(String(sticker.last_box));
    const [copies, setCopies] = useState('1');
    const [busy, setBusy] = useState(false);
    const [problem, setProblem] = useState<string | null>(null);

    const addUsb = (device: UsbDeviceLike) =>
        setChoices((c) =>
            c.some((x) => x.kind === 'usb' && x.device === device)
                ? c
                : [{ kind: 'usb', device, name: usbName(device) }, ...c],
        );

    useEffect(() => {
        void pairedUsbPrinters().then((devices) => devices.forEach(addUsb));
    }, []);

    const pick = (name: string) => {
        setChosen(name);
        writeChoice(name);
        setProblem(null);
    };

    const choice = choices.find((c) => c.name === chosen) ?? choices[0];
    const range = (a: string, b: string) => {
        const f = Math.max(sticker.first_box, Number(a) || sticker.first_box);
        const t = Math.min(
            sticker.last_box,
            Math.max(f, Number(b) || sticker.last_box),
        );

        return [f, t] as const;
    };

    const print = async (test = false) => {
        const [f, t] = test
            ? [sticker.first_box, sticker.first_box]
            : range(from, to);
        const n = Math.max(1, Math.min(10, Number(copies) || 1));
        setProblem(null);

        if (choice.kind === 'sticker_pdf' || choice.kind === 'a5') {
            window.open(
                cartons.print(lotId, {
                    query: { format: choice.kind, from: f, to: t },
                }).url,
                '_blank',
                'noopener',
            );

            return;
        }

        setBusy(true);

        try {
            const res = await fetch(
                cartons.tspl(lotId, { query: { from: f, to: t, copies: n } })
                    .url,
                { headers: { Accept: 'text/plain' } },
            );

            if (!res.ok) throw new Error(await res.text());

            const bytes = new TextEncoder().encode(await res.text());

            if (choice.kind === 'usb') {
                await sendUsb(choice.device, bytes);
            } else {
                await sendBluetooth(choice.device, bytes);
            }

            await fetch(cartons.printed(lotId).url, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': xsrf(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    printer: choice.name,
                    from: f,
                    to: t,
                    copies: n,
                }),
            });

            toast.success(
                `Sent ${t - f + 1} sticker${t === f ? '' : 's'} to ${choice.name}.`,
            );
        } catch (e) {
            setProblem(
                e instanceof Error ? e.message : 'The printer did not answer.',
            );
        } finally {
            setBusy(false);
        }
    };

    const icon = (c: Choice): LucideIcon =>
        c.kind === 'usb'
            ? Usb
            : c.kind === 'bluetooth'
              ? Bluetooth
              : c.kind === 'a5'
                ? FileText
                : Printer;

    return (
        <section className="bg-card flex flex-col gap-4 rounded-xl border p-5">
            <div className="flex items-center gap-2">
                <Printer className="text-primary size-5" />
                <h2 className="font-semibold">Print stickers</h2>
                <span className="text-muted-foreground ml-auto text-xs">
                    Boxes {sticker.first_box}–{sticker.last_box}
                </span>
            </div>

            <div className="flex flex-col gap-2">
                {choices.map((c) => {
                    const Icon = icon(c);
                    const active = c.name === choice.name;

                    return (
                        <button
                            key={c.name}
                            type="button"
                            onClick={() => pick(c.name)}
                            aria-pressed={active}
                            className={cn(
                                'flex items-center gap-3 rounded-lg border px-3 py-2.5 text-left text-sm',
                                active
                                    ? 'border-primary bg-primary/10'
                                    : 'hover:bg-muted',
                            )}
                        >
                            <Icon className="text-muted-foreground size-4 shrink-0" />
                            <span className="flex-1">
                                <span className="block font-medium">
                                    {c.name}
                                </span>
                                <span className="text-muted-foreground block text-xs">
                                    {c.kind === 'usb'
                                        ? 'USB · straight from this browser'
                                        : c.kind === 'bluetooth'
                                          ? 'Bluetooth · straight from this browser'
                                          : c.kind === 'sticker_pdf'
                                            ? 'Opens a PDF; print it on the TSC through its driver'
                                            : 'Opens a PDF; one A5 sheet per carton, as before'}
                                </span>
                            </span>
                        </button>
                    );
                })}
            </div>

            <div className="flex flex-wrap gap-2">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    disabled={!supportsUsb()}
                    onClick={async () => {
                        try {
                            const d = await requestUsbPrinter();
                            addUsb(d);
                            pick(usbName(d));
                        } catch (e) {
                            if (
                                e instanceof Error &&
                                e.name !== 'NotFoundError'
                            ) {
                                setProblem(e.message);
                            }
                        }
                    }}
                >
                    <Usb className="size-4" /> Add USB printer
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    disabled={!supportsBluetooth()}
                    onClick={async () => {
                        try {
                            const d = await requestBluetoothPrinter();
                            const name = d.name ?? 'Bluetooth printer';
                            setChoices((c) => [
                                { kind: 'bluetooth', device: d, name },
                                ...c.filter((x) => x.name !== name),
                            ]);
                            pick(name);
                        } catch (e) {
                            if (
                                e instanceof Error &&
                                e.name !== 'NotFoundError'
                            ) {
                                setProblem(e.message);
                            }
                        }
                    }}
                >
                    <Bluetooth className="size-4" /> Add Bluetooth printer
                </Button>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={() =>
                        void pairedUsbPrinters().then((d) => d.forEach(addUsb))
                    }
                >
                    <RefreshCw className="size-4" /> Look again
                </Button>
            </div>
            {!supportsUsb() && (
                <p className="text-muted-foreground text-xs">
                    This browser cannot reach a printer directly. Chrome or Edge
                    on a computer or Android phone can; here, use the 100 × 150
                    PDF through the TSC driver.
                </p>
            )}

            <div className="grid grid-cols-3 gap-2">
                <label className="flex flex-col gap-1 text-xs">
                    <span className="text-muted-foreground">From box</span>
                    <Input
                        type="number"
                        min={sticker.first_box}
                        max={sticker.last_box}
                        value={from}
                        onChange={(e) => setFrom(e.target.value)}
                    />
                </label>
                <label className="flex flex-col gap-1 text-xs">
                    <span className="text-muted-foreground">To box</span>
                    <Input
                        type="number"
                        min={sticker.first_box}
                        max={sticker.last_box}
                        value={to}
                        onChange={(e) => setTo(e.target.value)}
                    />
                </label>
                <label className="flex flex-col gap-1 text-xs">
                    <span className="text-muted-foreground">Copies each</span>
                    <Input
                        type="number"
                        min={1}
                        max={10}
                        value={copies}
                        onChange={(e) => setCopies(e.target.value)}
                        disabled={
                            choice.kind === 'sticker_pdf' ||
                            choice.kind === 'a5'
                        }
                    />
                </label>
            </div>

            {problem && (
                <p
                    role="alert"
                    className="rounded-lg border border-red-600/30 bg-red-500/10 p-3 text-sm text-red-800 dark:text-red-200"
                >
                    {problem}
                </p>
            )}

            <div className="flex flex-wrap gap-2">
                <Button
                    type="button"
                    onClick={() => void print()}
                    disabled={busy}
                >
                    <Printer className="size-4" />
                    {busy
                        ? 'Printing…'
                        : `Print ${range(from, to)[1] - range(from, to)[0] + 1} sticker${range(from, to)[1] === range(from, to)[0] ? '' : 's'}`}
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    onClick={() => void print(true)}
                    disabled={busy}
                >
                    Test print one
                </Button>
            </div>

            {prints.length > 0 && (
                <div className="border-t pt-3">
                    <h3 className="text-muted-foreground mb-1.5 text-xs font-semibold tracking-wide uppercase">
                        Printed
                    </h3>
                    <ul className="flex flex-col gap-1 text-xs">
                        {prints.map((p) => (
                            <li key={p.id} className="flex flex-wrap gap-x-2">
                                <span className="font-medium">
                                    Boxes {p.first_box}–{p.last_box}
                                    {p.copies > 1 ? ` × ${p.copies}` : ''}
                                </span>
                                <span className="text-muted-foreground">
                                    {p.format === 'a5'
                                        ? 'A5 PDF'
                                        : p.format === 'sticker_pdf'
                                          ? '100 × 150 PDF'
                                          : (p.printer ?? 'label printer')}
                                    {' · '}
                                    {p.by} ·{' '}
                                    {new Date(p.at).toLocaleString('en-IN', {
                                        day: 'numeric',
                                        month: 'short',
                                        hour: '2-digit',
                                        minute: '2-digit',
                                    })}
                                </span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </section>
    );
}
