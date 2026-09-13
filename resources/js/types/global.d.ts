import type { Auth } from '@/types/auth';

declare module 'react' {
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            erp: { company: string; timezone: string; currency_symbol: string };
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
    }
}
