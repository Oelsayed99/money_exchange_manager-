import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { RecordHeading } from './record-heading';

vi.mock('@inertiajs/react', () => ({
    Link: ({ children, href }: { children: ReactNode; href: string }) => <a href={href}>{children}</a>,
}));

// Keys rather than prose, so the test states which string was asked for and does not
// break when the wording is edited.
vi.mock('@/lib/i18n', () => ({ useTranslations: () => ({ t: (key: string) => key }) }));

describe('RecordHeading', () => {
    it('names the form that is showing', () => {
        render(<RecordHeading current="exchange" />);

        expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('transactions.exchange.title');
    });

    // The switch has to be a heading and a control at once. A <button> may not contain
    // an <h1>, so the trigger sits inside the heading — and it is easy to get that
    // round the wrong way and lose the page's only level-one heading.
    it('keeps the heading a heading', () => {
        render(<RecordHeading current="movement" />);

        const heading = screen.getByRole('heading', { level: 1 });

        expect(heading).toHaveTextContent('movements.title');
        expect(heading.querySelector('button')).not.toBeNull();
    });

    it('offers the other form, and says where it goes', async () => {
        const user = userEvent.setup();

        render(<RecordHeading current="exchange" />);

        await user.click(screen.getByRole('button'));

        expect(screen.getByRole('link', { name: /movements\.title/ })).toHaveAttribute('href', '/movements');
        expect(screen.getByRole('link', { name: /transactions\.exchange\.title/ })).toHaveAttribute('href', '/exchange');
    });

    it('falls back to a heading rather than nothing when handed a mode it does not know', () => {
        // @ts-expect-error — the point of the test is the value the types forbid.
        render(<RecordHeading current="something-else" />);

        expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('transactions.exchange.title');
    });
});
