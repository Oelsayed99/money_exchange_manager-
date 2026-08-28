import { LanguageSwitcher } from '@/components/language-switcher';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { useTranslations } from '@/lib/i18n';
import { usePermissions } from '@/lib/permissions';
import { type NavItem } from '@/types';
import { Link } from '@inertiajs/react';
import { ArrowLeftRight, Coins, Landmark, LayoutGrid, ReceiptText, Scale, ShieldCheck, Users } from 'lucide-react';
import AppLogo from './app-logo';

export function AppSidebar() {
    const { t, isRtl } = useTranslations();
    const { can } = usePermissions();

    // Built inside the component rather than at module scope so labels re-resolve when
    // the language changes. Section 12: no hardcoded interface strings.
    const mainNavItems: NavItem[] = [
        {
            title: t('nav.dashboard'),
            url: '/dashboard',
            icon: LayoutGrid,
        },
        // Navigation reflects permissions so a user is not sent to a page that will
        // refuse them. The route is guarded regardless.
        // Exchange and the other movements are one screen with a switch in its heading,
        // so one entry here rather than two. It opens on the exchange because that is
        // what a money-exchange business does all day; the heading offers the other.
        ...(can('transactions.record') ? [{ title: t('nav.record'), url: '/exchange', matches: ['/movements'], icon: ArrowLeftRight }] : []),
        ...(can('transactions.view') ? [{ title: t('nav.transactions'), url: '/transactions', icon: ReceiptText }] : []),
        ...(can('reconciliations.view') ? [{ title: t('nav.reconciliations'), url: '/reconciliations', icon: Scale }] : []),
        ...(can('accounts.view') ? [{ title: t('nav.accounts'), url: '/accounts', icon: Landmark }] : []),
        ...(can('counterparties.view') ? [{ title: t('nav.counterparties'), url: '/counterparties', icon: Users }] : []),
        ...(can('currencies.view') ? [{ title: t('nav.currencies'), url: '/currencies', icon: Coins }] : []),
        ...(can('audit.view') ? [{ title: t('nav.audit'), url: '/audit', icon: ShieldCheck }] : []),
    ];

    return (
        // A real RTL layout moves the sidebar to the right, not just the text inside it
        // (Section 12). The primitive already carries direction-aware variants keyed on
        // data-side; nothing was telling it which side to take.
        <Sidebar collapsible="icon" variant="inset" side={isRtl ? 'right' : 'left'}>
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href="/dashboard" prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                {/* The starter kit's links to Laravel's own repository and docs are
                    removed; they are not navigation for this application. */}
                <div className="px-2 group-data-[collapsible=icon]:hidden">
                    <LanguageSwitcher />
                </div>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
