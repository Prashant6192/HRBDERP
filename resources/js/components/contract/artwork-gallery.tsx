import { router } from '@inertiajs/react';
import { CheckCircle2, FileText, ImageOff, Trash2 } from 'lucide-react';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import {
    ARTWORK_KIND_LABEL,
    ARTWORK_STATUS_LABEL,
    ARTWORK_STATUS_VARIANT,
} from '@/lib/contract';
import { date } from '@/lib/stock';
import { cn } from '@/lib/utils';
import type { ArtworkRow } from '@/types';

/**
 * Artwork as the packing line sees it: the picture first, then the
 * version and its approval. Images show inline; a PDF opens in a tab.
 */
export function ArtworkGallery({
    artworks,
    statusUrl,
    destroyUrl,
    canEdit = false,
    compact = false,
    emptyText = 'No artwork on file.',
    className,
}: {
    artworks: ArtworkRow[];
    /** Where an approve / reject posts for a version; omit to hide. */
    statusUrl?: (artwork: ArtworkRow) => string;
    destroyUrl?: (artwork: ArtworkRow) => string;
    canEdit?: boolean;
    /** Smaller tiles, for a phone or a side panel. */
    compact?: boolean;
    emptyText?: string;
    className?: string;
}) {
    if (artworks.length === 0) {
        return (
            <p
                className={cn(
                    'text-muted-foreground px-5 py-6 text-sm',
                    className,
                )}
            >
                {emptyText}
            </p>
        );
    }

    const post = (url: string, data: Record<string, string>) =>
        router.post(url, data, { preserveScroll: true });

    return (
        <ul
            className={cn(
                'grid gap-3',
                compact
                    ? 'grid-cols-2'
                    : 'grid-cols-2 sm:grid-cols-3 lg:grid-cols-4',
                className,
            )}
        >
            {artworks.map((a) => (
                <li
                    key={a.id}
                    className={cn(
                        'bg-card flex flex-col overflow-hidden rounded-xl border',
                        a.status === 'approved'
                            ? 'border-emerald-500/50'
                            : a.status === 'pending'
                              ? 'border-amber-500/50'
                              : 'opacity-70',
                    )}
                >
                    {a.document ? (
                        <a
                            href={a.document.url}
                            target="_blank"
                            rel="noreferrer"
                            className="bg-muted/40 flex aspect-square items-center justify-center overflow-hidden"
                        >
                            {a.document.is_image ? (
                                <img
                                    src={a.document.url}
                                    alt={`${a.title} ${a.version}`}
                                    className="h-full w-full object-contain"
                                    loading="lazy"
                                />
                            ) : (
                                <span className="text-muted-foreground flex flex-col items-center gap-1 text-xs">
                                    <FileText className="size-8" />
                                    Open PDF
                                </span>
                            )}
                        </a>
                    ) : (
                        <div className="bg-muted/40 text-muted-foreground flex aspect-square flex-col items-center justify-center gap-1 text-xs">
                            <ImageOff className="size-8" />
                            No file
                        </div>
                    )}
                    <div className={cn('space-y-1', compact ? 'p-2' : 'p-3')}>
                        <div className="flex items-start justify-between gap-2">
                            <div className="min-w-0">
                                <div className="truncate text-sm font-medium">
                                    {a.title}
                                </div>
                                <div className="text-muted-foreground text-xs">
                                    {ARTWORK_KIND_LABEL[a.kind] ?? a.kind} ·{' '}
                                    <span className="font-mono">
                                        {a.version}
                                    </span>
                                </div>
                            </div>
                            <StatusBadge
                                variant={ARTWORK_STATUS_VARIANT[a.status]}
                            >
                                {ARTWORK_STATUS_LABEL[a.status]}
                            </StatusBadge>
                        </div>
                        {!compact && (
                            <div className="text-muted-foreground text-xs">
                                {a.client
                                    ? a.client
                                    : a.product
                                      ? a.product
                                      : 'All products'}
                                {a.approved_at
                                    ? ` · approved ${date(a.approved_at)}${a.approved_by_name ? ` by ${a.approved_by_name}` : ''}`
                                    : ''}
                            </div>
                        )}
                        {!compact && a.notes && (
                            <p className="text-muted-foreground text-xs">
                                {a.notes}
                            </p>
                        )}
                        {canEdit && (statusUrl || destroyUrl) && (
                            <div className="flex flex-wrap gap-1 pt-1">
                                {statusUrl && a.status === 'pending' && (
                                    <>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() =>
                                                post(statusUrl(a), {
                                                    status: 'approved',
                                                    approved_at: new Date()
                                                        .toISOString()
                                                        .slice(0, 10),
                                                })
                                            }
                                        >
                                            <CheckCircle2 className="size-4" />
                                            Approved
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            onClick={() =>
                                                post(statusUrl(a), {
                                                    status: 'rejected',
                                                })
                                            }
                                        >
                                            Rejected
                                        </Button>
                                    </>
                                )}
                                {destroyUrl && (
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        className="text-destructive"
                                        onClick={() => {
                                            if (
                                                window.confirm(
                                                    `Remove ${a.title} ${a.version}?`,
                                                )
                                            ) {
                                                router.delete(destroyUrl(a), {
                                                    preserveScroll: true,
                                                });
                                            }
                                        }}
                                    >
                                        <Trash2 className="size-4" />
                                    </Button>
                                )}
                            </div>
                        )}
                    </div>
                </li>
            ))}
        </ul>
    );
}
