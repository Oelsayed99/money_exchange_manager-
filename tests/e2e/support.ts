import { expect, type Page } from '@playwright/test';

/** Matches the seeded accounts in database/seeders/E2eSeeder.php. */
export const OWNER = { email: 'owner@e2e.test', password: 'e2e-password' };
export const CLERK = { email: 'clerk@e2e.test', password: 'e2e-password' };

export const CLIENT = 'سالم التجريبي';

export async function signIn(page: Page, who: { email: string; password: string } = OWNER): Promise<void> {
    await page.goto('/login');
    await page.getByLabel('Email address').fill(who.email);
    await page.getByLabel('Password', { exact: true }).fill(who.password);
    await page.getByRole('button', { name: 'Log in' }).click();

    await expect(page).toHaveURL(/dashboard/);
}

/**
 * Amounts are grouped where they are *displayed* and raw where they are *edited*.
 *
 * MoneyDisplay groups for reading; MoneyInput holds the plain decimal, because a
 * thousands separator in a field somebody is about to submit is a value the decimal
 * parser would reject. So: `grouped()` for text on the page, the raw string for
 * anything read back out of an input.
 */
export function grouped(amount: string): string {
    const [whole, fraction] = amount.split('.');

    return (whole ?? '').replace(/\B(?=(\d{3})+$)/g, ',') + (fraction === undefined ? '' : `.${fraction}`);
}

/**
 * Switch the interface language.
 *
 * The trigger is itself translated, so it is matched in either language — and the
 * preference is stored against the user, not the browser, which means it survives from
 * one test to the next. Every test that cares therefore sets it rather than assuming
 * whatever the last one left behind.
 */
export async function switchTo(page: Page, language: 'en' | 'ar'): Promise<void> {
    const current = await page.locator('html').getAttribute('lang');

    if (current === language) {
        return;
    }

    await page.getByRole('button', { name: /Language|اللغة/ }).click();
    await page.getByRole('menuitem', { name: language === 'ar' ? 'العربية' : 'English' }).click();

    await expect(page.locator('html')).toHaveAttribute('lang', language);
}
