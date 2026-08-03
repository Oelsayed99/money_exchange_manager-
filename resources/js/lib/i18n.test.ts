import type { TranslationBundle } from '@/types';
import { translate } from './i18n';

const bundle: TranslationBundle = {
    common: {
        save: 'Save',
        language: 'Language',
    },
    currencies: {
        title: 'Currencies',
        edit_title: 'Edit :code',
        fields: {
            code: 'Code',
        },
        hints: {
            code: 'ISO 4217 where one exists',
        },
    },
};

describe('translate', () => {
    it('resolves a top-level key', () => {
        expect(translate(bundle, 'currencies.title')).toBe('Currencies');
    });

    it('resolves a deeply nested key', () => {
        expect(translate(bundle, 'currencies.fields.code')).toBe('Code');
    });

    it('resolves a dynamically built key', () => {
        const field = 'code';

        expect(translate(bundle, `currencies.hints.${field}`)).toBe('ISO 4217 where one exists');
    });

    it('substitutes a placeholder', () => {
        expect(translate(bundle, 'currencies.edit_title', { code: 'AED' })).toBe('Edit AED');
    });

    it('substitutes every occurrence of a placeholder', () => {
        const repeated: TranslationBundle = { a: ':x and :x' };

        expect(translate(repeated, 'a', { x: 'one' })).toBe('one and one');
    });

    it('accepts numeric replacements', () => {
        const withCount: TranslationBundle = { a: ':count items' };

        expect(translate(withCount, 'a', { count: 3 })).toBe('3 items');
    });

    // Returning the key makes a missing translation obvious in the interface. An empty
    // string would silently swallow the mistake.
    it('returns the key when nothing matches', () => {
        expect(translate(bundle, 'currencies.nope')).toBe('currencies.nope');
        expect(translate(bundle, 'totally.absent.key')).toBe('totally.absent.key');
    });

    it('returns the key when it resolves to a group rather than a string', () => {
        expect(translate(bundle, 'currencies.fields')).toBe('currencies.fields');
    });

    it('returns the key when descending through a string', () => {
        expect(translate(bundle, 'common.save.deeper')).toBe('common.save.deeper');
    });

    it('handles an empty bundle without throwing', () => {
        expect(translate({}, 'anything')).toBe('anything');
    });

    it('leaves an unmatched placeholder untouched', () => {
        expect(translate(bundle, 'currencies.edit_title')).toBe('Edit :code');
    });
});
