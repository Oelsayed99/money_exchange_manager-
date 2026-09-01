/**
 * POST JSON with the CSRF token, returning null on anything but a success.
 *
 * A failed conversion leaves the previous figures alone rather than blanking them:
 * mid-typing states produce validation failures constantly, and clearing the form on
 * each one would be unusable.
 *
 * Shared by the two screens that work a figure out on the server while it is being
 * typed — the exchange form and the movement form. Both need exact decimal arithmetic
 * (Section 16), which is precisely what JavaScript cannot give them.
 */
export function postJson<T>(url: string, body: unknown, signal: AbortSignal): Promise<T | null> {
    return fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) ?? [])[1] ?? ''),
        },
        body: JSON.stringify(body),
        signal,
    }).then((response) => (response.ok ? (response.json() as Promise<T>) : null));
}
