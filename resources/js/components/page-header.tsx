import type { ReactNode } from 'react';

type PageHeaderProps = {
    title: string;
    description?: string;
    actions?: ReactNode;
};

export function PageHeader({ title, description, actions }: PageHeaderProps) {
    return (
        // The buttons move under the title when both do not fit, rather
        // than squeezing the title to a word a line.
        <div className="flex flex-wrap items-start justify-between gap-3">
            <div className="min-w-0 flex-[1_1_16rem] space-y-1">
                <h1 className="text-2xl font-semibold tracking-tight">
                    {title}
                </h1>
                {description && (
                    <p className="text-muted-foreground text-sm">
                        {description}
                    </p>
                )}
            </div>

            {actions && (
                <div className="flex max-w-full flex-wrap items-center gap-2">
                    {actions}
                </div>
            )}
        </div>
    );
}
