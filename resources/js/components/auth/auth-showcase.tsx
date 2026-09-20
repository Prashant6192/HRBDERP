import { useEffect, useState } from 'react';
import { WarehouseScene } from '@/components/auth/warehouse-scene';
import { cn } from '@/lib/utils';

/**
 * What this ERP is for, said three ways. Each is a claim the system
 * actually keeps, so the sign-in screen is not marketing at its own
 * staff.
 */
const SLIDES = [
    {
        title: 'Every batch, from weighing to the gate.',
        body: 'Plan it, make it, test it, ship it. The stock ledger and the audit trail keep their own record as it goes, and neither can be edited after the fact.',
    },
    {
        title: 'The floor writes it once.',
        body: 'Scan at the kettle, record the stage from a phone on the line. The office sees it the moment it happens; nobody keys it in twice.',
    },
    {
        title: 'Nothing ships unchecked.',
        body: 'A finished batch waits in quarantine until QC releases it, and the e-invoice is on record before the consignment leaves the yard.',
    },
];

const DWELL_MS = 7000;

export function AuthShowcase({ className }: { className?: string }) {
    const [index, setIndex] = useState(0);
    const [paused, setPaused] = useState(false);

    useEffect(() => {
        if (paused || typeof window === 'undefined') {
            return;
        }

        // Someone who has asked for less motion reads at their own pace.
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            return;
        }

        const id = window.setInterval(
            () => setIndex((i) => (i + 1) % SLIDES.length),
            DWELL_MS,
        );

        return () => window.clearInterval(id);
    }, [paused]);

    const slide = SLIDES[index];

    return (
        <div
            className={cn(
                'relative isolate overflow-hidden rounded-2xl',
                className,
            )}
            onMouseEnter={() => setPaused(true)}
            onMouseLeave={() => setPaused(false)}
        >
            <WarehouseScene className="absolute inset-0 size-full" />

            <div className="relative flex h-full flex-col justify-end gap-5 p-8 lg:p-10">
                <div key={index} className="animate-in fade-in duration-700">
                    <h2 className="text-2xl leading-tight font-semibold text-balance text-white lg:text-[1.75rem]">
                        {slide.title}
                    </h2>
                    <p className="mt-3 max-w-md text-sm leading-relaxed text-white/65">
                        {slide.body}
                    </p>
                </div>

                <div
                    className="flex items-center gap-2"
                    role="tablist"
                    aria-label="About this system"
                >
                    {SLIDES.map((s, i) => (
                        <button
                            key={s.title}
                            type="button"
                            role="tab"
                            aria-selected={i === index}
                            aria-label={s.title}
                            onClick={() => setIndex(i)}
                            className={cn(
                                'h-1.5 rounded-full transition-all duration-300',
                                i === index
                                    ? 'bg-primary w-8'
                                    : 'w-1.5 bg-white/35 hover:bg-white/60',
                            )}
                        />
                    ))}
                </div>
            </div>
        </div>
    );
}
