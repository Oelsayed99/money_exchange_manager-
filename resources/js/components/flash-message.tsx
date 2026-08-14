import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { Check } from 'lucide-react';

/** The success message left by the previous request, if there was one. */
export function FlashMessage() {
    const flash = usePage<SharedData>().props.flash;

    if (!flash?.success) {
        return null;
    }

    return (
        <div
            role="status"
            className="flex items-center gap-2 rounded-lg border border-emerald-600/30 bg-emerald-600/10 px-4 py-3 text-sm text-emerald-700 dark:text-emerald-400"
        >
            <Check className="size-4 shrink-0" aria-hidden="true" />
            <span>{flash.success}</span>
        </div>
    );
}
