import { render, screen } from '@testing-library/react';
import type { AnchorHTMLAttributes } from 'react';
import { AppSidebar } from './app-sidebar';
import { SidebarProvider } from './ui/sidebar';

const page = vi.hoisted(() => ({
    // NavMain reads this to decide which entry is the current one.
    url: '/dashboard',
    props: {
        locale: 'en',
        direction: 'ltr' as 'ltr' | 'rtl',
        locales: [{ code: 'en', native: 'English', direction: 'ltr' }],
        translations: {
            nav: { dashboard: 'Dashboard', currencies: 'Currencies', record: 'Record' },
            common: { language: 'Language' },
        },
        auth: { user: { name: 'Test', email: 't@example.com' }, permissions: [] as string[] },
    },
}));

vi.mock('@inertiajs/react', () => ({
    usePage: () => page,
    router: { on: vi.fn(), put: vi.fn() },
    // Spreads what it is given. SidebarMenuButton renders through the link with
    // asChild, so a stub that keeps only href swallows the very attribute that says
    // which entry is the current one.
    Link: ({ children, prefetch, ...props }: AnchorHTMLAttributes<HTMLAnchorElement> & { prefetch?: boolean }) => {
        void prefetch;

        return <a {...props}>{children}</a>;
    },
}));

function renderSidebar() {
    return render(
        <SidebarProvider>
            <AppSidebar />
        </SidebarProvider>,
    );
}

function sidebarElement(): HTMLElement {
    // data-variant as well as data-side: Radix popovers carry a data-side of their own,
    // and only the sidebar primitive sets a variant.
    const element = document.querySelector('[data-variant][data-side]');

    if (!(element instanceof HTMLElement)) {
        throw new Error('No sidebar carrying data-side was rendered.');
    }

    return element;
}

beforeEach(() => {
    page.url = '/dashboard';
    page.props.direction = 'ltr';
    page.props.auth.permissions = [];
});

describe('AppSidebar direction', () => {
    // The bug this covers: a real RTL layout moves the sidebar itself to the right,
    // not merely the text inside it. Nothing was passing `side` to the primitive.
    it('sits on the left in a left-to-right locale', () => {
        page.props.direction = 'ltr';

        renderSidebar();

        expect(sidebarElement()).toHaveAttribute('data-side', 'left');
    });

    it('moves to the right in a right-to-left locale', () => {
        page.props.direction = 'rtl';

        renderSidebar();

        expect(sidebarElement()).toHaveAttribute('data-side', 'right');
    });
});

describe('AppSidebar navigation', () => {
    it('always offers the dashboard', () => {
        renderSidebar();

        expect(screen.getByText('Dashboard')).toBeInTheDocument();
    });

    // Regression: gating navigation on a permission the user did not hold removed the
    // currencies link for every self-registered account, because registration assigned
    // no role at all.
    it('hides currencies from a user without permission', () => {
        page.props.auth.permissions = [];

        renderSidebar();

        expect(screen.queryByText('Currencies')).not.toBeInTheDocument();
    });

    it('shows currencies to a user who can view them', () => {
        page.props.auth.permissions = ['currencies.view'];

        renderSidebar();

        expect(screen.getByText('Currencies')).toBeInTheDocument();
    });
});

describe('AppSidebar current entry', () => {
    // One entry fronts both recording routes. Comparing the current URL to the entry's
    // own url alone would leave nothing at all marked while recording a movement.
    it.each(['/exchange', '/movements'])('marks the recording entry current at %s', (url) => {
        page.url = url;
        page.props.auth.permissions = ['transactions.record'];

        renderSidebar();

        expect(screen.getByText('Record').closest('a')).toHaveAttribute('data-active', 'true');
    });

    // The query string is part of page.url. Comparing it whole left the entry unmarked
    // the moment the operator applied a filter.
    it('marks an entry current even when a filter is applied', () => {
        page.url = '/exchange?from=USD';
        page.props.auth.permissions = ['transactions.record'];

        renderSidebar();

        expect(screen.getByText('Record').closest('a')).toHaveAttribute('data-active', 'true');
    });

    it('marks nothing current on a page that is not in the navigation', () => {
        page.url = '/settings/profile';
        page.props.auth.permissions = ['transactions.record'];

        renderSidebar();

        expect(screen.getByText('Record').closest('a')).toHaveAttribute('data-active', 'false');
    });
});
