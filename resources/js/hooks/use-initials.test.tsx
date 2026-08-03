import { renderHook } from '@testing-library/react';
import { useInitials } from './use-initials';

describe('useInitials', () => {
    const initials = () => renderHook(() => useInitials()).result.current;

    it('returns both initials for a two-part name', () => {
        expect(initials()('Omar Elsayed')).toBe('OE');
    });

    it('returns a single initial for a one-part name', () => {
        expect(initials()('Omar')).toBe('O');
    });

    it('uses the first and last part of a multi-part name', () => {
        expect(initials()('Omar Hesham Elsayed')).toBe('OE');
    });

    it('uppercases lowercase input', () => {
        expect(initials()('omar elsayed')).toBe('OE');
    });

    // Regression: these three previously read names[0] and names[names.length - 1]
    // without narrowing. Under noUncheckedIndexedAccess the reads are now guarded,
    // and empty or whitespace-only input must return '' rather than throw.
    it('returns an empty string for an empty name', () => {
        expect(initials()('')).toBe('');
    });

    it('returns an empty string for a whitespace-only name', () => {
        expect(initials()('   ')).toBe('');
    });

    it('ignores repeated spaces between name parts', () => {
        expect(initials()('Omar    Elsayed')).toBe('OE');
    });

    it('handles a non-Latin name', () => {
        expect(initials()('عمر السيد')).toBe('عا');
    });
});
