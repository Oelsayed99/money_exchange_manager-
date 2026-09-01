import MovementCreate from '@/pages/movements/create';
import { act, fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { useState } from 'react';

/**
 * Working out the other amount while it is being typed.
 *
 * The form used to multiply in JavaScript and print the answer as a hint, leaving the
 * operator to copy it into the field themselves — float arithmetic on money, and only
 * ever in one direction. It asks the server now, and the answer lands in the field.
 *
 * What these assert is the wiring: which field gets filled, which way round the request
 * goes, and that the form does not overwrite the field somebody is typing in.
 */
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, href }: { children: ReactNode; href: string }) => <a href={href}>{children}</a>,
    useForm: <T extends Record<string, unknown>>(initial: T) => {
        const [data, setState] = useState<T>(initial);

        const setData = (keyOrObject: string | Partial<T>, value?: unknown) =>
            setState((current) => (typeof keyOrObject === 'string' ? { ...current, [keyOrObject]: value } : { ...current, ...keyOrObject }));

        return { data, setData, post: vi.fn(), processing: false, errors: {} };
    },
}));

vi.mock('@/layouts/app-layout', () => ({ default: ({ children }: { children: ReactNode }) => <div>{children}</div> }));
vi.mock('@/components/flash-message', () => ({ FlashMessage: () => null }));
vi.mock('@/components/record-heading', () => ({ RecordHeading: () => null }));
vi.mock('@/lib/i18n', () => ({ useTranslations: () => ({ t: (key: string) => key }) }));

const props = {
    types: [
        {
            value: 'in',
            label: 'In',
            needsCounterparty: true,
            needsDestinationAccount: false,
            needsBucket: false,
            mayConvert: true,
            increases: false,
        },
    ],
    accounts: [{ id: 10, name: 'Main safe' }],
    currencies: [
        { id: 1, code: 'USD' },
        { id: 2, code: 'EGP' },
    ],
    counterparties: [{ id: 5, name: 'Salem' }],
    methods: [],
};

let convertRequests: Record<string, unknown>[] = [];
let convertResponse: Record<string, unknown> = {};

beforeEach(() => {
    vi.useFakeTimers();
    convertRequests = [];
    convertResponse = {
        solved_for: 'base_amount',
        rate: '50.850000000000',
        base_amount: { amount: '10000.00', currency: 'USD' },
        quote_amount: { amount: '508500.00', currency: 'EGP' },
        exact: true,
    };

    vi.stubGlobal(
        'fetch',
        vi.fn((url: string, init: { body: string }) => {
            if (url === '/exchange/convert') {
                convertRequests.push(JSON.parse(init.body));

                return Promise.resolve({ ok: true, json: () => Promise.resolve(convertResponse) });
            }

            // The standing panel is not what these are about.
            return Promise.resolve({ ok: false, json: () => Promise.resolve(null) });
        }),
    );
});

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

/** Fill in enough of a converting movement to make the form ask the server. */
async function converting(overrides: { amount?: string; cash?: string; rate?: string } = {}) {
    render(<MovementCreate {...props} />);

    fireEvent.change(screen.getByLabelText('movements.currency'), { target: { value: '2' } });
    fireEvent.change(screen.getByLabelText('movements.cash_currency'), { target: { value: '1' } });

    if (overrides.amount !== undefined) {
        fireEvent.change(screen.getByLabelText('movements.amount'), { target: { value: overrides.amount } });
    }

    if (overrides.cash !== undefined) {
        fireEvent.change(screen.getByLabelText('movements.cash_amount'), { target: { value: overrides.cash } });
    }

    fireEvent.change(screen.getByLabelText('movements.rate'), { target: { value: overrides.rate ?? '50.85' } });

    await act(async () => {
        vi.advanceTimersByTime(400);
    });
}

it('works the actually-moved figure out from the amount and the rate', async () => {
    await converting({ amount: '508500' });

    // The client's side and the rate are the facts; the cash is what follows.
    expect(convertRequests.at(-1)).toMatchObject({
        base_currency_id: '1',
        quote_currency_id: '2',
        rate: '50.85',
        quote_amount: '508500',
    });

    expect(screen.getByLabelText('movements.cash_amount')).toHaveValue('10000.00');
});

// The owner's own first example runs the other way: they hold the dollars and want the
// pounds booked. Whichever field is not being typed in is the one that follows.
it('works the amount out from what actually moved, when that is what was typed', async () => {
    convertResponse = { ...convertResponse, solved_for: 'quote_amount' };

    await converting({ cash: '10000' });

    expect(convertRequests.at(-1)).toMatchObject({ base_amount: '10000', rate: '50.85' });
    expect(screen.getByLabelText('movements.amount')).toHaveValue('508500.00');
});

it('says which figure it worked out', async () => {
    await converting({ amount: '508500' });

    expect(screen.getByText('transactions.exchange.computed')).toBeInTheDocument();
});

// Division does not always terminate, and the figure is cut rather than rounded — so
// the operator has to be told before they hand over money against it.
it('warns when the figure had to be cut', async () => {
    convertResponse = { ...convertResponse, exact: false };

    await converting({ amount: '1000000' });

    expect(screen.getByText('transactions.exchange.inexact')).toBeInTheDocument();
});

it('asks nothing until there is a rate and an amount to work from', async () => {
    await converting({ amount: '', rate: '' });

    expect(convertRequests).toHaveLength(0);
});

it('leaves both fields alone when the movement is in one currency', async () => {
    render(<MovementCreate {...props} />);

    fireEvent.change(screen.getByLabelText('movements.amount'), { target: { value: '508500' } });

    await act(async () => {
        vi.advanceTimersByTime(400);
    });

    expect(convertRequests).toHaveLength(0);
});
