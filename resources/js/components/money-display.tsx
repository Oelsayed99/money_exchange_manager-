import { cn } from '@/lib/utils';

interface MoneyDisplayProps {
    /** Always a string. JavaScript numbers are float64 and would corrupt an exact amount. */
    amount: string;
    currency: string;
    className?: string;
    /** Colour negatives red and show an explicit sign. Off by default: most amounts are neutral. */
    signed?: boolean;
}

/**
 * Renders a monetary amount.
 *
 * Three things this gets right that ad-hoc markup does not:
 *
 * - The amount is a string end to end. It is never parsed into a JavaScript number,
 *   because float64 cannot hold an exact decimal (risk R1).
 * - The number itself is always laid out left to right, even in an Arabic interface.
 *   Digits and their grouping do not mirror; only the surrounding text does.
 * - Tabular figures, so columns of amounts line up on the decimal point.
 */
export function MoneyDisplay({ amount, currency, className, signed = false }: MoneyDisplayProps) {
    const isNegative = amount.startsWith('-');

    return (
        <span
            dir="ltr"
            className={cn(
                'inline-flex items-baseline gap-1.5 font-mono whitespace-nowrap tabular-nums',
                signed && isNegative && 'text-red-700 dark:text-red-400',
                className,
            )}
        >
            <span className="text-muted-foreground text-xs">{currency}</span>
            <span>{amount}</span>
        </span>
    );
}
