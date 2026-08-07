import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { AppSidebar } from './app-sidebar';
import { SidebarProvider } from './ui/sidebar';

const page = vi.hoisted(() => ({
    props: {
        locale: 'en',
        direction: 'ltr' as 'ltr' | 'rtl',
        locales: [{ code: 'en', native: 'English', direction: 'ltr' }],
        translations: {
            nav: { dashboard: 'Dashboard', currencies: 'Currencies' },
            common: { language: 'Language' },
        },
        auth: { user: { name: 'Test', email: 't@example.com' }, permissions: [] as string[] },
    },
}));

vi.mock('@inertiajs/react', () => ({
    usePage: () => page,
    router: { on: vi.fn(), put: vi.fn() },
    Link: ({ children, href }: { children: ReactNode; href: string }) => <a href={href}>{children}</a>,
}));

function renderSidebar() {
    return render(
        <SidebarProvider>
            <AppSidebar />
        </SidebarProvider>,
    );
}

function sidebarElement(): HTMLElement {
    const element = document.querySelector('[data-side]');

    if (!(element instanceof HTMLElement)) {
        throw new Error('No element carrying data-side was rendered.');
    }

    return element;
}

beforeEach(() => {
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
