import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { StatusBadge } from '@/components/status-badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { ALERT_LABEL, ALERT_VARIANT, qty } from '@/lib/stock';
import { show as showPackaging } from '@/routes/packaging-materials';
import { show as showRawMaterial } from '@/routes/raw-materials';
import type { RequirementLineRow } from '@/types';

/**
 * A plan's requirement for one store: what it needs, what is there, what is
 * short, and how the store's alert level moves if the batch is made.
 */
export function RequirementTable({
    lines,
    emptyText,
}: {
    lines: RequirementLineRow[];
    emptyText: string;
}) {
    if (lines.length === 0) {
        return (
            <p className="text-muted-foreground px-5 py-6 text-sm">
                {emptyText}
            </p>
        );
    }

    return (
        <div className="overflow-x-auto">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead className="w-10">#</TableHead>
                        <TableHead>Material</TableHead>
                        <TableHead className="text-right">Required</TableHead>
                        <TableHead className="text-right">In store</TableHead>
                        <TableHead className="text-right">Short</TableHead>
                        <TableHead className="text-right">
                            Order to restock
                        </TableHead>
                        <TableHead>Store level</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {lines.map((line) => {
                        const short = Number(line.shortage) > 0;
                        const href =
                            line.item_type === 'packaging_material'
                                ? showPackaging(line.item_id)
                                : showRawMaterial(line.item_id);

                        return (
                            <TableRow
                                key={line.id}
                                className={short ? 'bg-red-500/5' : undefined}
                            >
                                <TableCell className="text-muted-foreground">
                                    {line.line_no}
                                </TableCell>
                                <TableCell>
                                    <Link
                                        href={href}
                                        className="font-medium underline-offset-4 hover:underline"
                                    >
                                        {line.item_name}
                                    </Link>
                                    <div className="text-muted-foreground text-xs">
                                        {line.item_code}
                                        {line.percentage
                                            ? ` · ${Number(line.percentage)}%`
                                            : line.is_qs
                                              ? ' · QS'
                                              : ''}
                                    </div>
                                    {line.notes.map((n, i) => (
                                        <div
                                            key={i}
                                            className="text-xs text-amber-700 dark:text-amber-300"
                                        >
                                            {n}
                                        </div>
                                    ))}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {line.as_required
                                        ? 'as required'
                                        : `${qty(line.required)} ${line.uom}`}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {qty(line.available)} {line.uom}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {short ? (
                                        <span className="font-semibold text-red-700 dark:text-red-300">
                                            {qty(line.shortage)} {line.uom}
                                        </span>
                                    ) : (
                                        <span className="text-muted-foreground">
                                            —
                                        </span>
                                    )}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {Number(line.restock) > 0
                                        ? `${qty(line.restock)} ${line.uom}`
                                        : '—'}
                                </TableCell>
                                <TableCell>
                                    <div className="flex items-center gap-1.5 whitespace-nowrap">
                                        <StatusBadge
                                            variant={
                                                ALERT_VARIANT[line.level_now]
                                            }
                                        >
                                            {ALERT_LABEL[line.level_now]}
                                        </StatusBadge>
                                        {!line.as_required && (
                                            <>
                                                <ArrowRight className="text-muted-foreground size-3" />
                                                <StatusBadge
                                                    variant={
                                                        ALERT_VARIANT[
                                                            line.level_after
                                                        ]
                                                    }
                                                >
                                                    {
                                                        ALERT_LABEL[
                                                            line.level_after
                                                        ]
                                                    }
                                                </StatusBadge>
                                            </>
                                        )}
                                    </div>
                                </TableCell>
                            </TableRow>
                        );
                    })}
                </TableBody>
            </Table>
        </div>
    );
}
