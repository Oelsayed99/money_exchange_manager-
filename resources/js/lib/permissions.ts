import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/react';

/**
 * Permission checks for the interface.
 *
 * **This is presentation, not security.** Hiding a button stops a user being offered
 * an action that would be refused; it does not stop the action. Section 16 requires
 * authorization to be enforced on the backend, and it is — by policies and form
 * requests, which run whether or not the client ever rendered the control.
 *
 * The shared props list only the permissions the user actually holds, so the client
 * never receives the shape of the full matrix.
 */
export function usePermissions() {
    const permissions = usePage<SharedData>().props.auth.permissions;

    const can = (permission: string): boolean => permissions.includes(permission);

    return {
        can,
        canAny: (...candidates: string[]): boolean => candidates.some(can),
    };
}
