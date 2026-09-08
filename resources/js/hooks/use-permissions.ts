import { usePage } from '@inertiajs/react';
import type { SharedData } from '@/types';

/**
 * What the signed-in user is allowed to do.
 *
 * This exists to decide what to render — hiding a button nobody can use is
 * kinder than showing one that fails. It is not a security boundary: every
 * action is authorised again by a policy on the server, and anything that
 * relied on this alone would be bypassed by anyone who opened the console.
 */
export function usePermissions() {
    const page = usePage<SharedData>();
    const auth = page.props.auth;

    const permissions = auth?.permissions ?? [];
    const roles = auth?.roles ?? [];

    const can = (permission: string): boolean =>
        auth?.isSuperAdmin === true || permissions.includes(permission);

    const canAny = (...wanted: string[]): boolean => wanted.some(can);

    const canAll = (...wanted: string[]): boolean => wanted.every(can);

    const hasRole = (role: string): boolean => roles.includes(role);

    return { can, canAny, canAll, hasRole, permissions, roles };
}
