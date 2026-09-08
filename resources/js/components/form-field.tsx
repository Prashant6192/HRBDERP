import type { ReactNode } from 'react';
import InputError from '@/components/input-error';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

type FieldProps = {
    label: string;
    htmlFor: string;
    error?: string;
    hint?: string;
    required?: boolean;
    className?: string;
    children: ReactNode;
};

export function Field({
    label,
    htmlFor,
    error,
    hint,
    required,
    className,
    children,
}: FieldProps) {
    return (
        <div className={cn('space-y-2', className)}>
            <Label htmlFor={htmlFor}>
                {label}
                {required && (
                    <span className="text-destructive ml-0.5" aria-hidden>
                        *
                    </span>
                )}
            </Label>

            {children}

            {hint && !error && (
                <p className="text-muted-foreground text-xs">{hint}</p>
            )}

            <InputError message={error} />
        </div>
    );
}

/**
 * A titled group of fields. Long ERP forms are unreadable without them.
 */
export function FormSection({
    title,
    description,
    children,
}: {
    title: string;
    description?: string;
    children: ReactNode;
}) {
    return (
        <section className="bg-card rounded-xl border p-6">
            <div className="mb-5 space-y-1">
                <h2 className="font-semibold">{title}</h2>
                {description && (
                    <p className="text-muted-foreground text-sm">
                        {description}
                    </p>
                )}
            </div>

            <div className="grid gap-5 sm:grid-cols-2">{children}</div>
        </section>
    );
}

/**
 * A read-only label/value pair, for detail screens.
 */
export function DetailItem({
    label,
    children,
}: {
    label: string;
    children: ReactNode;
}) {
    return (
        <div className="space-y-1">
            <dt className="text-muted-foreground text-xs tracking-wide uppercase">
                {label}
            </dt>
            <dd className="text-sm">{children ?? '—'}</dd>
        </div>
    );
}
