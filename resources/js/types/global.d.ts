import type { Auth } from '@/types/auth';
import type { ErpNotification } from '@/types/erp';

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
            notifications: {
                unread: number;
                latest: ErpNotification[];
            } | null;
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
    }
}
