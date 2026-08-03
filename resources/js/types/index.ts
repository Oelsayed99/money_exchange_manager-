import { LucideIcon } from 'lucide-react';

export interface Auth {
    user: User;
    /** Only the permissions this user holds. Presentation only — the backend is the gate. */
    permissions: string[];
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavGroup {
    title: string;
    items: NavItem[];
}

export interface NavItem {
    title: string;
    url: string;
    icon?: LucideIcon | null;
    isActive?: boolean;
}

export type Direction = 'ltr' | 'rtl';

export interface LocaleOption {
    code: string;
    native: string;
    direction: Direction;
}

/** Arbitrarily nested translation groups, as shipped by HandleInertiaRequests. */
export type TranslationBundle = {
    [key: string]: string | TranslationBundle;
};

export interface SharedData {
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    locale: string;
    direction: Direction;
    locales: LocaleOption[];
    translations: TranslationBundle;
    flash: { success: string | null };
    [key: string]: unknown;
}

export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    locale: string | null;
    theme: string | null;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
    [key: string]: unknown; // This allows for additional properties...
}
