import { groupDigits } from './money';

describe('grouping digits for reading', () => {
    it.each([
        ['0.00', '0.00'],
        ['1.00', '1.00'],
        ['999.00', '999.00'],
        ['1000.00', '1,000.00'],
        ['999999.00', '999,999.00'],
        ['2574000.00', '2,574,000.00'],
        ['3957540.00', '3,957,540.00'],
        ['1234567890.00', '1,234,567,890.00'],
    ])('groups %s as %s', (amount, expected) => {
        expect(groupDigits(amount)).toBe(expected);
    });

    it('keeps the sign in front of the digits', () => {
        expect(groupDigits('-2574000.00')).toBe('-2,574,000.00');
    });

    it('leaves the fraction alone however long it is', () => {
        expect(groupDigits('1234.5678901234')).toBe('1,234.5678901234');
    });

    it('handles an amount with no fraction at all', () => {
        expect(groupDigits('2574000')).toBe('2,574,000');
    });

    // A float64 cannot hold this exactly. Parsing to format would silently change it,
    // which is the failure the whole money layer exists to prevent.
    it('does not go through a JavaScript number', () => {
        expect(groupDigits('9007199254740993.01')).toBe('9,007,199,254,740,993.01');
    });

    it('changes no digit of the value', () => {
        expect(groupDigits('3957540.12').replace(/,/g, '')).toBe('3957540.12');
    });

    // Showing a raw value is recoverable; showing a mangled one is not.
    it('passes anything unexpected through untouched', () => {
        expect(groupDigits('')).toBe('');
        expect(groupDigits('—')).toBe('—');
        expect(groupDigits('1,000.00')).toBe('1,000.00');
    });
});
