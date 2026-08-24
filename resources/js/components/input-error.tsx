import { cn } from '@/lib/utils';
import { HTMLAttributes } from 'react';

/**
 * A validation message for one field.
 *
 * `role="alert"` matters more than it looks. Without it the message simply appears —
 * silently, to anybody using a screen reader — and the first they know of a rejected
 * form is that the page has not moved on. With it, the message is announced the moment
 * it renders, which is the moment it is relevant.
 *
 * It is still not *associated* with its input: that needs an `aria-describedby` on
 * every field pointing at an id here, and the fields are spread across a dozen forms.
 * Announcing beats silence; associating would be better. See ADR 0021.
 */
export default function InputError({ message, className = '', ...props }: HTMLAttributes<HTMLParagraphElement> & { message?: string }) {
    return message ? (
        <p {...props} role="alert" className={cn('text-sm text-red-600 dark:text-red-400', className)}>
            {message}
        </p>
    ) : null;
}
