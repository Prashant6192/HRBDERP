import { Head } from '@inertiajs/react';

type LabelRow = {
    id: number;
    code: string;
    name: string | null;
    type: string | null;
    scan_code: string;
    qr: string;
};

/**
 * Rack and shelf labels for a store. Each carries a QR that opens the
 * location on the floor scanner; the first label is the store itself.
 */
export default function StoreLabels({
    store,
    labels,
    company,
}: {
    store: {
        id: number;
        code: string;
        name: string;
        facility: string | null;
        qr: string;
        scan_code: string;
    };
    labels: LabelRow[];
    company: string;
}) {
    const all = [
        {
            id: 0,
            code: store.code,
            name: `${store.name} (store)`,
            type: 'store',
            scan_code: store.scan_code,
            qr: store.qr,
        },
        ...labels,
    ];

    return (
        <>
            <Head title={`Labels ${store.code}`} />
            <style>{`
                @page { size: A4 portrait; margin: 10mm; }
                @media print { .no-print { display: none !important; } body { background: white; } .label { break-inside: avoid; } }
            `}</style>
            <div className="mx-auto max-w-[190mm] bg-white p-4 font-sans text-black">
                <div className="no-print mb-4 flex items-center justify-between">
                    <p className="text-sm text-gray-600">
                        {all.length} label{all.length === 1 ? '' : 's'} for{' '}
                        {store.name}
                        {store.facility ? ` · ${store.facility}` : ''}. Stick
                        one on each rack or shelf.
                    </p>
                    <button
                        type="button"
                        onClick={() => window.print()}
                        className="rounded border px-3 py-1 text-sm"
                    >
                        Print
                    </button>
                </div>
                <div className="grid grid-cols-2 gap-4">
                    {all.map((l) => (
                        <div
                            key={l.id}
                            className="label flex items-center gap-3 rounded-lg border-2 border-black p-3"
                        >
                            <img
                                src={l.qr}
                                alt={l.scan_code}
                                className="size-28 shrink-0"
                            />
                            <div className="min-w-0">
                                <p className="text-[10px] tracking-wide text-gray-600 uppercase">
                                    {company}
                                </p>
                                <p className="truncate text-2xl font-bold">
                                    {l.code}
                                </p>
                                <p className="truncate text-sm">
                                    {l.name ?? ''}
                                </p>
                                <p className="text-xs text-gray-600">
                                    {store.code}
                                    {l.type && l.type !== 'store'
                                        ? ` · ${l.type}`
                                        : ''}
                                </p>
                                <p className="mt-1 font-mono text-[10px] text-gray-500">
                                    {l.scan_code}
                                </p>
                            </div>
                        </div>
                    ))}
                </div>
            </div>
        </>
    );
}
