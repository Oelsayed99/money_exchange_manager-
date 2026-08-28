import ExchangeCreate from '@/pages/exchange/create';
import { act, fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { useState } from 'react';

// A working stand-in for Inertia's useForm: the page is built around data changing in
// response to typing, so a stub that does not actually hold state would test nothing.
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    // The heading offers a link to the other recording form.
    Link: ({ children, href }: { children: ReactNode; href: string }) => <a href={href}>{children}</a>,
    useForm: <T extends Record<string, unknown>>(initial: T) => {
        const [data, setState] = useState<T>(initial);

        const setData = (keyOrObject: string | Partial<T>, value?: unknown) =>
            setState((current) => (typeof keyOrObject === 'string' ? { ...current, [keyOrObject]: value } : { ...current, ...keyOrObject }));

        return { data, setData, post: vi.fn(), processing: false, errors: {} };
    },
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <div>{children}</div>,
}));

vi.mock('@/components/flash-message', () => ({ FlashMessage: () => null }));

// Keys rather than prose: the test then states which string the page asked for, and
// does not break when the wording is edited.
vi.mock('@/lib/i18n', () => ({ useTranslations: () => ({ t: (key: string) => key }) }));

const props = {
    currencies: [
        { id: 1, code: 'USD', decimal_places: 2 },
        { id: 2, code: 'EGP', decimal_places: 2 },
    ],
    accounts: [{ id: 10, name: 'Main safe' }],
    counterparties: [],
    profitMethods: [{ value: 'rate_difference', label: 'Rate difference', needsCostRate: true, isStatedDirectly: false }],
    spreadTypes: [],
    methods: [],
};

/** What the server would answer, keyed by the quantity the page left blank. */
let convertResponse: Record<string, unknown> = {};
let convertRequests: Record<string, unknown>[] = [];

beforeEach(() => {
    vi.useFakeTimers();
    convertRequests = [];
    convertResponse = {
        solved_for: 'quote_amount',
        rate: '51.480000000000',
        base_amount: { amount: '50000.00', currency: 'USD' },
        quote_amount: { amount: '2574000.00', currency: 'EGP' },
        exact: true,
    };

    vi.stubGlobal(
        'fetch',
        vi.fn((url: string, init: { body: string }) => {
            if (url === '/exchange/convert') {
                convertRequests.push(JSON.parse(init.body));

                return Promise.resolve({ ok: true, json: () => Promise.resolve(convertResponse) });
            }

            // The profit preview is not what these tests are about; leaving it
            // unanswered keeps the calculation panel in its empty state.
            return Promise.resolve({ ok: false, json: () => Promise.resolve(null) });
        }),
    );
});

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

/** Let the debounce elapse and the fetch promise settle. */
async function settle() {
    await act(async () => {
        vi.advanceTimersByTime(400);
    });
}

function type(label: string, value: string) {
    fireEvent.change(screen.getByLabelText(label), { target: { value } });
}

describe('entering a deal by its rate', () => {
    // The owner's own example: "I want 100k USD from someone, I will pay him in AED,
    // the rate is 3.67" — one amount and a rate, and the other amount follows.
    it('works out what you owe from the amount you are buying and the rate', async () => {
        render(<ExchangeCreate {...props} />);

        type('transactions.exchange.buying', '50000');
        type('transactions.exchange.rate', '51.48');
        await settle();

        expect(convertRequests).toContainEqual({
            base_currency_id: '1',
            quote_currency_id: '2',
            rate: '51.48',
            base_amount: '50000',
        });

        expect(screen.getByLabelText('transactions.exchange.paying_in')).toHaveValue('2574000.00');
    });

    // Every amount is a string from the keyboard to the column. A number anywhere in
    // this path is a float64, which is the failure the whole money layer prevents.
    it('sends and receives amounts as strings', async () => {
        render(<ExchangeCreate {...props} />);

        type('transactions.exchange.buying', '50000');
        type('transactions.exchange.rate', '51.48');
        await settle();

        for (const value of Object.values(convertRequests[0] ?? {})) {
            expect(typeof value).toBe('string');
        }
    });

    it('says nothing about precision when the figure came out even', async () => {
        render(<ExchangeCreate {...props} />);

        type('transactions.exchange.buying', '50000');
        type('transactions.exchange.rate', '51.48');
        await settle();

        expect(screen.queryByText('transactions.exchange.inexact')).not.toBeInTheDocument();
    });

    // The other example: EGP against euros at 54.20, which does not divide evenly.
    // The operator has to be told before they quote the figure to somebody.
    it('warns when the figure had to be cut', async () => {
        convertResponse = { ...convertResponse, exact: false, quote_amount: { amount: '18450.184501845', currency: 'EGP' } };

        render(<ExchangeCreate {...props} />);

        type('transactions.exchange.buying', '1000000');
        type('transactions.exchange.rate', '54.20');
        await settle();

        expect(screen.getByText('transactions.exchange.inexact')).toBeInTheDocument();
    });

    it('does nothing until it has both a rate and an amount', async () => {
        render(<ExchangeCreate {...props} />);

        type('transactions.exchange.buying', '50000');
        await settle();

        expect(convertRequests).toHaveLength(0);
    });
});

describe('overriding a computed figure', () => {
    // The rate follows the money, not the other way round: once the operator types the
    // amount actually settled, the rate is re-derived from the two amounts.
    it('re-derives the rate when the computed amount is typed over', async () => {
        render(<ExchangeCreate {...props} />);

        type('transactions.exchange.buying', '50000');
        type('transactions.exchange.rate', '51.48');
        await settle();

        convertResponse = {
            solved_for: 'rate',
            rate: '51.400000000000',
            base_amount: { amount: '50000.00', currency: 'USD' },
            quote_amount: { amount: '2570000.00', currency: 'EGP' },
            exact: true,
        };

        type('transactions.exchange.paying_in', '2570000');
        await settle();

        expect(convertRequests.at(-1)).toEqual({
            base_currency_id: '1',
            quote_currency_id: '2',
            base_amount: '50000',
            quote_amount: '2570000',
        });

        // Padded to rate precision on the wire, trimmed for the eye, same number.
        expect(screen.getByLabelText('transactions.exchange.rate')).toHaveValue('51.4');
    });
});

describe('which way round the deal is', () => {
    it('puts what you are buying on the received leg', async () => {
        render(<ExchangeCreate {...props} />);

        type('transactions.exchange.buying', '50000');
        type('transactions.exchange.rate', '51.48');
        await settle();

        expect(screen.getByText('50000 USD')).toBeInTheDocument();
        expect(screen.getByText('2574000.00 EGP')).toBeInTheDocument();
    });

    // Selling is the same two currencies and the opposite two legs. The amount the
    // operator typed stays with the currency they typed it against.
    it('moves it to the delivered leg when you are selling', async () => {
        render(<ExchangeCreate {...props} />);

        type('transactions.exchange.buying', '50000');
        await act(async () => {
            fireEvent.change(screen.getByLabelText('transactions.exchange.direction'), { target: { value: 'sell' } });
        });

        expect(screen.getByLabelText('transactions.exchange.selling')).toHaveValue('50000');
        expect(screen.getByText('50000 USD')).toBeInTheDocument();
    });
});

describe('which way round the rate is', () => {
    it('quotes against the currency being traded by default', () => {
        render(<ExchangeCreate {...props} />);

        expect(screen.getByText('1 USD =').parentElement).toHaveTextContent('EGP');
    });

    // A dealer quotes whichever way the market does. "1 EUR = 54.20 EGP" is how the
    // owner stated their second example, with the traded currency second.
    it('turns the quote round on request', async () => {
        render(<ExchangeCreate {...props} />);

        await act(async () => {
            fireEvent.click(screen.getByLabelText('transactions.exchange.swap_rate'));
        });

        expect(screen.getByText('1 EGP =')).toBeInTheDocument();
    });

    it('clears a rate that no longer means what it did', async () => {
        render(<ExchangeCreate {...props} />);

        type('transactions.exchange.rate', '51.48');

        await act(async () => {
            fireEvent.click(screen.getByLabelText('transactions.exchange.swap_rate'));
        });

        expect(screen.getByLabelText('transactions.exchange.rate')).toHaveValue('');
    });
});
