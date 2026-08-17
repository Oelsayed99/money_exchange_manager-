import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

interface MoneyInputProps {
    id?: string;
    /** The value is a decimal string throughout. It is never converted to a number. */
    value: string;
    onChange: (value: string) => void;
    currency?: string;
    className?: string;
    disabled?: boolean;
    /** For inputs whose meaning comes from surrounding text rather than a <label>. */
    'aria-label'?: string;
}

/**
 * An input for a monetary amount.
 *
 * Deliberately `type="text"`, not `type="number"`. A number input hands back a
 * JavaScript float, silently reformats what was typed, and lets a scroll wheel change
 * an amount — all unacceptable for money. Keystrokes are filtered to digits, one
 * decimal point and a leading minus, and the value stays a string from the keyboard
 * to the database column.
 */
export function MoneyInput({ id, value, onChange, currency, className, disabled, 'aria-label': ariaLabel }: MoneyInputProps) {
    const handle = (raw: string) => {
        // Arabic-Indic digits are normalised so an Arabic keyboard produces a value the
        // decimal parser accepts.
        const normalised = raw
            .replace(/[٠-٩]/g, (d) => String(d.charCodeAt(0) - 0x0660))
            .replace(/[۰-۹]/g, (d) => String(d.charCodeAt(0) - 0x06f0))
            .replace(/[٫,]/g, '.');

        // Keep only what a decimal may contain, and only one point.
        const cleaned = normalised.replace(/[^\d.-]/g, '');
        const [head, ...rest] = cleaned.split('.');
        const joined = rest.length > 0 ? `${head}.${rest.join('')}` : (head ?? '');

        // A minus sign is only meaningful at the front.
        const negative = joined.startsWith('-');

        onChange((negative ? '-' : '') + joined.replace(/-/g, ''));
    };

    return (
        <div className={cn('relative', className)}>
            <Input
                id={id}
                type="text"
                aria-label={ariaLabel}
                inputMode="decimal"
                autoComplete="off"
                dir="ltr"
                disabled={disabled}
                value={value}
                onChange={(event) => handle(event.target.value)}
                className={cn('font-mono tabular-nums', currency && 'pe-14')}
            />
            {currency && (
                <span className="text-muted-foreground pointer-events-none absolute inset-y-0 end-3 flex items-center text-xs" dir="ltr">
                    {currency}
                </span>
            )}
        </div>
    );
}
