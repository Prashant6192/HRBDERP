import { Head } from '@inertiajs/react';
import { qty } from '@/lib/stock';
import type { ManufacturingOrder } from '@/types';

type Line = {
    id: number;
    store_kind: string;
    planned_quantity: string;
    item?: { code: string; name: string } | null;
    uom?: { code: string } | null;
};

/**
 * The batch card that travels with the kettle: the order's QR, what goes
 * in, and space for the floor's readings. Prints on A5.
 */
export default function BatchCard({
    order,
    qr,
    code,
    company,
}: {
    order: ManufacturingOrder & {
        lines?: Line[];
        facility?: { name: string } | null;
    };
    qr: string;
    code: string;
    company: string;
}) {
    const lines = order.lines ?? [];

    return (
        <>
            <Head title={`Batch card ${order.number}`} />
            <style>{`
                @page { size: A5 portrait; margin: 8mm; }
                @media print { .no-print { display: none !important; } body { background: white; } }
            `}</style>
            <div className="mx-auto max-w-[148mm] bg-white p-4 font-sans text-black">
                <div className="no-print mb-3 flex justify-end">
                    <button
                        type="button"
                        onClick={() => window.print()}
                        className="rounded border px-3 py-1 text-sm"
                    >
                        Print
                    </button>
                </div>
                <div className="flex items-start justify-between gap-3 border-b-2 border-black pb-2">
                    <div>
                        <p className="text-xs tracking-wide uppercase">
                            {company} · Batch card
                        </p>
                        <h1 className="text-2xl font-bold">{order.number}</h1>
                        <p className="text-sm">
                            {order.product?.name ?? order.formula?.name} ·{' '}
                            {order.formula?.code} v
                            {order.formula_version?.version_number}
                        </p>
                        <p className="text-sm">
                            {qty(order.planned_quantity)}{' '}
                            {order.planned_uom?.code}
                            {order.planned_units
                                ? ` · ${order.planned_units} units`
                                : ''}
                            {order.client ? ` · for ${order.client.name}` : ''}
                            {order.facility ? ` · ${order.facility.name}` : ''}
                        </p>
                    </div>
                    <div className="text-center">
                        <img src={qr} alt={code} className="size-24" />
                        <p className="font-mono text-[10px]">{code}</p>
                    </div>
                </div>

                <table className="mt-3 w-full text-sm">
                    <thead>
                        <tr className="border-b border-black text-left">
                            <th className="py-1">Material</th>
                            <th className="py-1 text-right">Standard</th>
                            <th className="py-1">Batch scanned</th>
                            <th className="py-1 text-right">Actual</th>
                        </tr>
                    </thead>
                    <tbody>
                        {lines.map((l) => (
                            <tr key={l.id} className="border-b border-gray-300">
                                <td className="py-1.5">
                                    {l.item?.name}
                                    <span className="block text-[10px] text-gray-600">
                                        {l.item?.code} ·{' '}
                                        {l.store_kind === 'packaging'
                                            ? 'packaging'
                                            : 'raw material'}
                                    </span>
                                </td>
                                <td className="py-1.5 text-right tabular-nums">
                                    {qty(l.planned_quantity)} {l.uom?.code}
                                </td>
                                <td className="py-1.5"> </td>
                                <td className="py-1.5"> </td>
                            </tr>
                        ))}
                    </tbody>
                </table>

                <div className="mt-4 grid grid-cols-2 gap-3 text-xs">
                    {[
                        'Weighing',
                        'Charging',
                        'Mixing',
                        'Heating',
                        'Cooling',
                        'In-process QC',
                        'Filling',
                        'Packaging',
                    ].map((s) => (
                        <div
                            key={s}
                            className="flex items-center justify-between border-b border-gray-300 py-1"
                        >
                            <span>{s}</span>
                            <span className="text-gray-500">
                                time ______ by ______
                            </span>
                        </div>
                    ))}
                </div>

                <p className="mt-4 text-[10px] text-gray-600">
                    Scan the code on a phone to open this batch on the floor:
                    issue materials by scanning each drum, record the stage,
                    attach photos.
                </p>
            </div>
        </>
    );
}
