import { expect, test } from '@playwright/test';
import { signIn, switchTo } from './support';

/**
 * One recording screen with two forms, switched from its own heading.
 *
 * Exchange and the other movements used to be two entries in the navigation, which
 * asked an operator to decide what kind of thing they were recording before they had
 * anywhere to record it. This is the switch that replaced that decision, and the only
 * test that walks it: the unit tests cover the heading in isolation and know nothing
 * about whether the form underneath actually changes.
 */
test.beforeEach(async ({ page }) => {
    await signIn(page);

    // The language is stored against the user and outlives a test.
    await switchTo(page, 'en');
});

test('switches between the two forms from the heading', async ({ page }) => {
    await page.goto('/exchange');

    await expect(page.getByRole('heading', { level: 1 })).toHaveText(/Currency exchange/);

    // Something only the exchange form has.
    await expect(page.getByLabel('This deal')).toBeVisible();

    await page.getByRole('button', { name: /Currency exchange/ }).click();
    await page.getByRole('menuitem', { name: /Record a movement/ }).click();

    await expect(page).toHaveURL(/\/movements$/);
    await expect(page.getByRole('heading', { level: 1 })).toHaveText(/Record a movement/);

    // The form underneath really did change, not just the words above it.
    await expect(page.getByLabel('This deal')).toBeHidden();
    await expect(page.getByLabel('What happened')).toBeVisible();

    // And back, which is the half that a one-way switch would pass without.
    await page.getByRole('button', { name: /Record a movement/ }).click();
    await page.getByRole('menuitem', { name: /Currency exchange/ }).click();

    await expect(page).toHaveURL(/\/exchange$/);
    await expect(page.getByLabel('This deal')).toBeVisible();
});

test('keeps one entry in the navigation, current from either form', async ({ page }) => {
    await page.goto('/movements');

    const entry = page.getByRole('link', { name: 'Record', exact: true });

    await expect(entry).toHaveAttribute('data-active', 'true');

    await page.goto('/exchange');

    await expect(entry).toHaveAttribute('data-active', 'true');
});

test('switches in Arabic too, with the menu on the right side of the heading', async ({ page }) => {
    await page.goto('/exchange');
    await switchTo(page, 'ar');

    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');

    await page.getByRole('button', { name: /صرف/ }).first().click();
    await page.getByRole('menuitem', { name: /تسجيل حركة/ }).click();

    await expect(page).toHaveURL(/\/movements$/);
    await expect(page.getByRole('heading', { level: 1 })).toHaveText(/تسجيل حركة/);
});
