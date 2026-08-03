import { LanguageSwitcher } from '@/components/language-switcher';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { useTranslations } from '@/lib/i18n';
import { usePermissions } from '@/lib/permissions';
import { type NavItem } from '@/types';
import { Link } from '@inertiajs/react';
import { Coins, LayoutGrid } from 'lucide-react';
import AppLogo from './app-logo';

export function AppSidebar() {
    const { t } = useTranslations();
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
        ...(can('currencies.view')
            ? [
                  {
                      title: t('nav.currencies'),
                      url: '/currencies',
                      icon: Coins,
                  },
              ]
            : []),
    ];

    return (
        <Sidebar collapsible="icon" variant="inset">
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
