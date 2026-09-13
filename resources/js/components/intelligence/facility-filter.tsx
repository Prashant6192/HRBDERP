import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { FacilityChip } from '@/lib/intelligence';

/**
 * "All facilities" or one of them. Hidden when there is only one to choose.
 */
export function FacilityFilter({
    facilities,
    value,
    onChange,
}: {
    facilities: FacilityChip[];
    value: number | null;
    onChange: (facility: number | null) => void;
}) {
    if (facilities.length < 2) {
        return null;
    }

    return (
        <Select
            value={value ? String(value) : 'all'}
            onValueChange={(v) => onChange(v === 'all' ? null : Number(v))}
        >
            <SelectTrigger className="min-w-48">
                <SelectValue placeholder="All facilities" />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value="all">All facilities</SelectItem>
                {facilities.map((f) => (
                    <SelectItem key={f.id} value={String(f.id)}>
                        {f.name}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

/**
 * A row of headline figures above a report table.
 */
export function StatTile({
    label,
    value,
    hint,
    tone = 'default',
}: {
    label: string;
    value: string | number;
    hint?: string;
    tone?: 'default' | 'warning' | 'danger' | 'success';
}) {
    const tones = {
        default: 'text-foreground',
        warning: 'text-amber-700 dark:text-amber-300',
        danger: 'text-red-700 dark:text-red-300',
        success: 'text-emerald-700 dark:text-emerald-300',
    };

    return (
        <div className="bg-card rounded-2xl border p-4">
            <p className="text-muted-foreground text-xs font-medium tracking-wide uppercase">
                {label}
            </p>
            <p
                className={`mt-1 text-2xl font-semibold tracking-tight tabular-nums ${tones[tone]}`}
            >
                {typeof value === 'number'
                    ? value.toLocaleString('en-IN')
                    : value}
            </p>
            {hint && (
                <p className="text-muted-foreground mt-1 text-xs">{hint}</p>
            )}
        </div>
    );
}
