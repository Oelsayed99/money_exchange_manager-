export function useInitials() {
    const getInitials = (fullName: string): string => {
        // Filter empty segments so runs of whitespace, or an empty name, cannot
        // produce an out-of-bounds read. `noUncheckedIndexedAccess` requires the
        // indexed reads below to be narrowed rather than assumed present.
        const names = fullName.trim().split(' ').filter(Boolean);

        const first = names[0];
        if (first === undefined) return '';

        const last = names.length > 1 ? names[names.length - 1] : undefined;
        if (last === undefined) return first.charAt(0).toUpperCase();

        return `${first.charAt(0)}${last.charAt(0)}`.toUpperCase();
    };

    return getInitials;
}
