import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { useTranslations } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';

export default function AppSidebarLayout({ children, breadcrumbs = [] }: { children: React.ReactNode; breadcrumbs?: BreadcrumbItem[] }) {
    const { t } = useTranslations();

    return (
        <AppShell variant="sidebar">
            {/*
                Hidden until it has focus, which is the point: the first Tab on any page
                offers a way past the navigation. Without it a keyboard or screen-reader
                user walks every sidebar link again on every page they open.
            */}
            <a
                href="#main"
                className="bg-background focus:ring-ring sr-only focus:not-sr-only focus:absolute focus:start-4 focus:top-4 focus:z-50 focus:rounded-md focus:border focus:px-4 focus:py-2 focus:ring-2"
            >
                {t('common.skip_to_content')}
            </a>

            <AppSidebar />
            <AppContent variant="sidebar">
                <AppSidebarHeader breadcrumbs={breadcrumbs} />
                {/* tabIndex allows the skip link to move focus here, not merely scroll. */}
                <main id="main" tabIndex={-1}>
                    {children}
                </main>
            </AppContent>
        </AppShell>
    );
}
