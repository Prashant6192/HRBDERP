import { Head } from '@inertiajs/react';
import { Bot, Send, Sparkles, User as UserIcon } from 'lucide-react';
import { useEffect, useRef, useState, type FormEvent } from 'react';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { ask, index as assistantIndex } from '@/routes/assistant';

type Turn = {
    role: 'user' | 'assistant';
    content: string;
    tools?: { name: string; input: Record<string, unknown> }[];
    error?: boolean;
};

const TOOL_LABEL: Record<string, string> = {
    find_items: 'Searched materials',
    stock_outlook: 'Read the stock outlook',
    reorder_advice: 'Read the reorder advice',
    factory_exceptions: 'Read the exception feed',
    command_centre: 'Read the command centre',
    order_status: 'Read the order',
    trace_batch: 'Traced the batch',
    scorecards: 'Read the scorecards',
    stock_risks: 'Read slow-moving stock and expiry risk',
    client_profitability: 'Read client profitability',
};

async function send(
    question: string,
    history: Turn[],
): Promise<{ answer: string; tools: Turn['tools'] }> {
    const token =
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? '';
    const response = await fetch(ask().url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': decodeURIComponent(
                document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? token,
            ),
        },
        body: JSON.stringify({
            question,
            history: history
                .filter((t) => !t.error)
                .map((t) => ({ role: t.role, content: t.content })),
        }),
    });
    const body = (await response.json().catch(() => ({}))) as {
        answer?: string;
        tools?: Turn['tools'];
        error?: string;
        message?: string;
    };
    if (!response.ok) {
        throw new Error(
            body.error ??
                body.message ??
                `The assistant did not answer (${response.status}).`,
        );
    }
    return { answer: body.answer ?? '', tools: body.tools ?? [] };
}

export default function Assistant({
    available,
    suggestions,
}: {
    available: boolean;
    suggestions: string[];
}) {
    const [turns, setTurns] = useState<Turn[]>([]);
    const [question, setQuestion] = useState('');
    const [busy, setBusy] = useState(false);
    const bottom = useRef<HTMLDivElement | null>(null);

    useEffect(() => {
        bottom.current?.scrollIntoView({ behavior: 'smooth', block: 'end' });
    }, [turns, busy]);

    const submit = async (text: string) => {
        const q = text.trim();
        if (q === '' || busy || !available) return;
        const history = turns;
        setTurns((t) => [...t, { role: 'user', content: q }]);
        setQuestion('');
        setBusy(true);
        try {
            const reply = await send(q, history);
            setTurns((t) => [
                ...t,
                {
                    role: 'assistant',
                    content: reply.answer,
                    tools: reply.tools,
                },
            ]);
        } catch (e) {
            setTurns((t) => [
                ...t,
                {
                    role: 'assistant',
                    content:
                        e instanceof Error
                            ? e.message
                            : 'The assistant did not answer.',
                    error: true,
                },
            ]);
        } finally {
            setBusy(false);
        }
    };

    const onSubmit = (e: FormEvent) => {
        e.preventDefault();
        void submit(question);
    };

    return (
        <>
            <Head title="Ask the ERP" />
            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title="Ask the ERP"
                    description="Ask in plain words. The assistant reads stock, orders, batches, exceptions and scorecards under your own permissions, and never changes anything."
                />

                {!available && (
                    <div className="rounded-xl border border-amber-500/30 bg-amber-500/5 p-4 text-sm">
                        The assistant is switched off on this server. Setting{' '}
                        <span className="font-mono">ANTHROPIC_API_KEY</span>{' '}
                        turns it on.
                    </div>
                )}

                <div className="bg-card flex min-h-[60vh] flex-col rounded-2xl border">
                    <div className="flex-1 space-y-4 overflow-y-auto p-4 sm:p-6">
                        {turns.length === 0 && (
                            <div className="mx-auto max-w-2xl py-8 text-center">
                                <Sparkles className="text-primary mx-auto size-8" />
                                <p className="mt-3 font-medium">
                                    What do you want to know?
                                </p>
                                <div className="mt-4 grid gap-2 sm:grid-cols-2">
                                    {suggestions.map((s) => (
                                        <button
                                            key={s}
                                            type="button"
                                            disabled={!available}
                                            onClick={() => void submit(s)}
                                            className="bg-background hover:bg-muted rounded-xl border px-3 py-2 text-left text-sm disabled:opacity-50"
                                        >
                                            {s}
                                        </button>
                                    ))}
                                </div>
                            </div>
                        )}
                        {turns.map((t, i) => (
                            <div
                                key={i}
                                className={cn(
                                    'flex gap-3',
                                    t.role === 'user' && 'flex-row-reverse',
                                )}
                            >
                                <span
                                    className={cn(
                                        'flex size-8 shrink-0 items-center justify-center rounded-full',
                                        t.role === 'user'
                                            ? 'bg-primary text-primary-foreground'
                                            : 'bg-muted',
                                    )}
                                >
                                    {t.role === 'user' ? (
                                        <UserIcon className="size-4" />
                                    ) : (
                                        <Bot className="size-4" />
                                    )}
                                </span>
                                <div
                                    className={cn(
                                        'max-w-[85%] space-y-1',
                                        t.role === 'user' && 'text-right',
                                    )}
                                >
                                    <div
                                        className={cn(
                                            'inline-block rounded-2xl px-4 py-2.5 text-left text-sm whitespace-pre-wrap',
                                            t.role === 'user'
                                                ? 'bg-primary text-primary-foreground'
                                                : t.error
                                                  ? 'border border-red-500/30 bg-red-500/5'
                                                  : 'bg-muted',
                                        )}
                                    >
                                        {t.content}
                                    </div>
                                    {t.tools && t.tools.length > 0 && (
                                        <p className="text-muted-foreground text-xs">
                                            {Array.from(
                                                new Set(
                                                    t.tools.map(
                                                        (u) =>
                                                            TOOL_LABEL[
                                                                u.name
                                                            ] ?? u.name,
                                                    ),
                                                ),
                                            ).join(' · ')}
                                        </p>
                                    )}
                                </div>
                            </div>
                        ))}
                        {busy && (
                            <div className="flex gap-3">
                                <span className="bg-muted flex size-8 shrink-0 items-center justify-center rounded-full">
                                    <Bot className="size-4" />
                                </span>
                                <div className="bg-muted text-muted-foreground inline-block rounded-2xl px-4 py-2.5 text-sm">
                                    Reading the ERP…
                                </div>
                            </div>
                        )}
                        <div ref={bottom} />
                    </div>
                    <form
                        onSubmit={onSubmit}
                        className="flex items-end gap-2 border-t p-3"
                    >
                        <textarea
                            value={question}
                            onChange={(e) => setQuestion(e.target.value)}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter' && !e.shiftKey) {
                                    e.preventDefault();
                                    void submit(question);
                                }
                            }}
                            rows={2}
                            disabled={!available || busy}
                            placeholder={
                                available
                                    ? 'e.g. Do we have enough Surfactant A for next week?'
                                    : 'The assistant is switched off.'
                            }
                            className="bg-background focus-visible:ring-ring flex-1 resize-none rounded-xl border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none disabled:opacity-50"
                        />
                        <Button
                            type="submit"
                            disabled={
                                !available || busy || question.trim() === ''
                            }
                            className="h-10"
                        >
                            <Send className="size-4" />
                            Ask
                        </Button>
                    </form>
                </div>
            </div>
        </>
    );
}

Assistant.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Ask the ERP', href: assistantIndex() },
    ],
};
