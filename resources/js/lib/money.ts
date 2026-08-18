/**
 * Group an amount's digits for reading: "3957540.00" becomes "3,957,540.00".
 *
 * **Presentation only.** The result is not a valid decimal, and the backend rejects a
 * thousands separator on the way in — a grouped figure is ambiguous about which
 * separator means what, and the ambiguity is worst in exactly the locales this
 * application serves. Never put this in a form field, a request body, or anywhere it
 * could come back; amounts stay plain decimal strings everywhere except the moment
 * they are drawn.
 *
 * String surgery only. Parsing to a JavaScript number to format it would put a float64
 * in the path of a figure the whole money layer exists to keep exact — 3957540.00 is
 * small enough to survive, but the amounts this business deals in are not all small.
 */
export function groupDigits(amount: string): string {
    const negative = amount.startsWith('-');
    const bare = negative ? amount.slice(1) : amount;

    const separator = bare.indexOf('.');
    const whole = separator === -1 ? bare : bare.slice(0, separator);
    const fraction = separator === -1 ? '' : bare.slice(separator);

    // Anything unexpected is passed through untouched rather than mangled: showing the
    // raw value is recoverable, showing a corrupted one is not.
    if (!/^\d+$/.test(whole)) {
        return amount;
    }

    const grouped = whole.replace(/\B(?=(\d{3})+$)/g, ',');

    return (negative ? '-' : '') + grouped + fraction;
}
