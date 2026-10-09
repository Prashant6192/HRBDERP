import { router } from '@inertiajs/react';
import { ArrowRight, FileDown, FileUp, TriangleAlert } from 'lucide-react';
import { useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { cn } from '@/lib/utils';
import { apply, download, read } from '@/routes/stores/ingredients';

type Change = {
    id: number;
    old_code: string;
    old_name: string;
    code: string;
    name: string;
    problem: string | null;
};

type Reading = {
    changes: Change[];
    unchanged: number;
    problems: string[];
};

/**
 * The raw material store's ingredients in Excel: download the list,
 * correct codes and names, upload it, look over every change, apply.
 */
export function IngredientSheet({
    storeId,
    canUpload,
}: {
    storeId: number;
    canUpload: boolean;
}) {
    const input = useRef<HTMLInputElement>(null);
    const [reading, setReading] = useState<Reading | null>(null);
    const [busy, setBusy] = useState(false);
    const [problem, setProblem] = useState<string | null>(null);

    const upload = async (file: File | undefined) => {
        if (!file) {
            return;
        }

        setBusy(true);
        setProblem(null);

        try {
            const body = new FormData();
            body.append('sheet', file);
            const token = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
            const response = await fetch(read(storeId).url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': token ? decodeURIComponent(token[1]) : '',
                },
                body,
            });
            const json = (await response.json()) as Reading & {
                message?: string;
                errors?: Record<string, string[]>;
            };

            if (!response.ok) {
                setProblem(
                    json.message ??
                        Object.values(json.errors ?? {})[0]?.[0] ??
                        'The sheet could not be read.',
                );
                setReading({ changes: [], unchanged: 0, problems: [] });

                return;
            }

            setReading(json);
        } catch {
            setProblem('Could not reach the server. Try again.');
            setReading({ changes: [], unchanged: 0, problems: [] });
        } finally {
            setBusy(false);

            if (input.current) {
                input.current.value = '';
            }
        }
    };

    const good = reading?.changes.filter((c) => c.problem === null) ?? [];
    const bad = reading?.changes.filter((c) => c.problem !== null) ?? [];

    const save = () => {
        setBusy(true);
        router.post(
            apply(storeId).url,
            {
                changes: good.map((c) => ({
                    id: c.id,
                    code: c.code,
                    name: c.name,
                })),
            },
            {
                preserveScroll: true,
                onSuccess: () => setReading(null),
                onFinish: () => setBusy(false),
            },
        );
    };

    return (
        <>
            <Button variant="outline" asChild>
                <a href={download(storeId).url}>
                    <FileDown className="size-4" />
                    Ingredient list (Excel)
                </a>
            </Button>
            {canUpload && (
                <>
                    <Button
                        variant="outline"
                        disabled={busy}
                        onClick={() => input.current?.click()}
                    >
                        <FileUp className="size-4" />
                        {busy && reading === null
                            ? 'Reading…'
                            : 'Upload edited list'}
                    </Button>
                    <input
                        ref={input}
                        type="file"
                        accept=".xlsx,.xls,.csv"
                        className="hidden"
                        aria-label="Edited ingredient list"
                        onChange={(e) => void upload(e.target.files?.[0])}
                    />
                </>
            )}

            <Dialog
                open={reading !== null}
                onOpenChange={(open) => !open && !busy && setReading(null)}
            >
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
                    <DialogHeader>
                        <DialogTitle>Check the changes</DialogTitle>
                        <DialogDescription>
                            {problem
                                ? 'The sheet could not be used.'
                                : `${good.length} ingredient(s) will change. ${reading?.unchanged ?? 0} row(s) are unchanged. Nothing is saved until you apply.`}
                        </DialogDescription>
                    </DialogHeader>

                    {problem && (
                        <p className="rounded-lg border border-red-600/30 bg-red-500/10 p-3 text-sm text-red-800 dark:text-red-200">
                            {problem}
                        </p>
                    )}

                    {(bad.length > 0 ||
                        (reading?.problems.length ?? 0) > 0) && (
                        <div className="space-y-1 rounded-lg bg-amber-500/15 p-3 text-sm text-amber-900 dark:text-amber-100">
                            <p className="flex items-center gap-2 font-medium">
                                <TriangleAlert className="size-4" />
                                {bad.length +
                                    (reading?.problems.length ?? 0)}{' '}
                                row(s) will be left as they are
                            </p>
                            <ul className="list-disc space-y-0.5 pl-5 text-xs">
                                {reading?.problems.map((p) => (
                                    <li key={p}>{p}</li>
                                ))}
                                {bad.map((c) => (
                                    <li key={c.id}>
                                        {c.old_code} ({c.old_name}): {c.problem}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}

                    {good.length > 0 && (
                        <div className="divide-y rounded-lg border text-sm">
                            {good.map((c) => (
                                <div
                                    key={c.id}
                                    className="grid gap-1 px-3 py-2 sm:grid-cols-2 sm:gap-4"
                                >
                                    <Changed
                                        label="Code"
                                        from={c.old_code}
                                        to={c.code}
                                        mono
                                    />
                                    <Changed
                                        label="Name"
                                        from={c.old_name}
                                        to={c.name}
                                    />
                                </div>
                            ))}
                        </div>
                    )}

                    {!problem &&
                        good.length === 0 &&
                        bad.length === 0 &&
                        (reading?.problems.length ?? 0) === 0 && (
                            <p className="text-muted-foreground text-sm">
                                Nothing on the sheet differs from the ERP.
                            </p>
                        )}

                    <DialogFooter>
                        <Button
                            variant="outline"
                            disabled={busy}
                            onClick={() => setReading(null)}
                        >
                            Cancel
                        </Button>
                        <Button
                            disabled={busy || good.length === 0}
                            onClick={save}
                        >
                            {busy
                                ? 'Saving…'
                                : `Apply ${good.length} change${good.length === 1 ? '' : 's'}`}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

function Changed({
    label,
    from,
    to,
    mono = false,
}: {
    label: string;
    from: string;
    to: string;
    mono?: boolean;
}) {
    const same = from === to;

    return (
        <div className="min-w-0">
            <div className="text-muted-foreground text-[11px] tracking-wide uppercase">
                {label}
            </div>
            {same ? (
                <div className={cn('truncate', mono && 'font-mono')}>{to}</div>
            ) : (
                <div className="flex flex-wrap items-center gap-1.5">
                    <span
                        className={cn(
                            'text-muted-foreground line-through',
                            mono && 'font-mono',
                        )}
                    >
                        {from}
                    </span>
                    <ArrowRight className="text-muted-foreground size-3.5 shrink-0" />
                    <span className={cn('font-medium', mono && 'font-mono')}>
                        {to}
                    </span>
                </div>
            )}
        </div>
    );
}
