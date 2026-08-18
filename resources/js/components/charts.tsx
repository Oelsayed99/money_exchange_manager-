import { MoneyDisplay } from '@/components/money-display';
import { groupDigits } from '@/lib/money';
import type { ReactNode } from 'react';

/**
 * Chart plumbing shared by the dashboard's statistics.
 *
 * ## The one place a float touches money
 *
 * A chart draws with SVG coordinates, and SVG coordinates are floats; no charting
 * library avoids that. So a bar's *height* goes through `Number`.
 *
 * Nothing a reader sees does. Axis ticks and tooltips are rendered from the exact
 * decimal string the server sent, and the plotted value is never displayed. The line
 * is: float decides where the pixel goes, the exact string says what the number is.
 */

/** A point ready to plot: what to draw with, and what to show. */
export interface Plotted {
    label: string;
    height: number;
    exact: string;
}

export function plot(label: string, amount: string): Plotted {
    return { label, height: Number(amount), exact: amount };
}

/** Axis ticks, grouped, without going near the value that produced them. */
export function axisTick(value: number): string {
    return groupDigits(String(value));
}

/**
 * A tooltip showing exact figures.
 *
 * Takes the rows already resolved rather than digging through Recharts' payload at the
 * call site, so the exact string is what gets rendered every time.
 */
export function ExactTooltip({ title, rows, currency }: { title: string; rows: { label: string; amount: string }[]; currency: string }) {
    return (
        <div className="bg-background rounded-md border px-2 py-1.5 text-xs shadow-sm">
            <div className="text-muted-foreground mb-1">{title}</div>
            <div className="space-y-0.5">
                {rows.map((row) => (
                    <div key={row.label} className="flex items-baseline justify-between gap-3">
                        <span className="text-muted-foreground">{row.label}</span>
                        <MoneyDisplay amount={row.amount} currency={currency} signed />
                    </div>
                ))}
            </div>
        </div>
    );
}

/** A titled panel, so every chart on the page frames itself the same way. */
export function ChartPanel({ title, hint, children }: { title: ReactNode; hint?: string; children: ReactNode }) {
    return (
        <div className="space-y-2 rounded-xl border p-4">
            <div>
                <h2 className="text-sm font-medium">{title}</h2>
                {hint !== undefined && <p className="text-muted-foreground text-xs">{hint}</p>}
            </div>
            {children}
        </div>
    );
}

/** Shown in a chart's place when it has nothing to draw. */
export function ChartEmpty({ title, message }: { title: string; message: string }) {
    return (
        <ChartPanel title={title}>
            <p className="text-muted-foreground text-sm">{message}</p>
        </ChartPanel>
    );
}
