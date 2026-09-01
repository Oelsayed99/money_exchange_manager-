import { expect, test, type Page } from '@playwright/test';
import { signIn, switchTo } from './support';

/**
 * On a phone, the table scrolls sideways — the page does not.
 *
 * A list of transactions is wider than a phone and always will be: six columns of dates,
 * names and figures do not usefully reflow. So the tables scroll inside their own box.
 * What must not happen is the *page* scrolling with them, which drags the heading, the
 * filters and the buttons off the side of the screen and leaves the operator dragging
 * the whole layout back and forth to reach anything.
 *
 * Every list screen had this, from one missing `min-w-0`: a flex item will not shrink
 * below its content unless told it may, so the widest table on a page set the width of
 * everything.
 */
const PHONE = { width: 375, height: 812 };

/** Pages built around a table, and something on each that proves it really rendered. */
const TABLE_PAGES: [name: string, url: string][] = [
    ['dashboard', '/dashboard'],
    ['clients', '/counterparties'],
    ['statement', `/counterparties/1/statement`],
    ['transactions', '/transactions'],
    ['accounts', '/accounts'],
    ['currencies', '/currencies'],
    ['audit', '/audit'],

    // Not tables, but the same failure mode: a wide panel, a filter bar of six selects,
    // or a two-column form that will not shrink.
    ['reconciliation', '/reconciliations'],
    ['record a movement', '/movements'],
    ['exchange', '/exchange'],
    ['add a client', '/counterparties/create'],
    ['add an account', '/accounts/create'],
];

async function pageScrollsSideways(page: Page): Promise<{ scrollWidth: number; clientWidth: number }> {
    return page.evaluate(() => ({
        scrollWidth: document.documentElement.scrollWidth,
        clientWidth: document.documentElement.clientWidth,
    }));
}

test.describe('at 375px', () => {
    test.use({ viewport: PHONE });

    test.beforeEach(async ({ page }) => {
        await signIn(page);
        await switchTo(page, 'en');
    });

    for (const [name, url] of TABLE_PAGES) {
        test(`keeps the ${name} page within the screen`, async ({ page }) => {
            await page.goto(url);
            await expect(page.getByRole('heading', { level: 1 })).toBeVisible();

            const { scrollWidth, clientWidth } = await pageScrollsSideways(page);

            // A pixel of slack for sub-pixel layout rounding; anything more is a
            // column's worth of table hanging off the side.
            expect(scrollWidth, `${name} scrolls the whole page sideways`).toBeLessThanOrEqual(clientWidth + 1);
        });
    }

    // The other half of the requirement. A page that fits because its table was made to
    // wrap into an unreadable mess would pass the assertions above.
    test('still lets the table itself scroll', async ({ page }) => {
        await page.goto('/transactions');

        const scroller = page.locator('div.overflow-x-auto').first();

        const overflows = await scroller.evaluate((el) => el.scrollWidth > el.clientWidth);

        expect(overflows, 'the transactions table should scroll inside its own box').toBe(true);
    });

    // Arabic mirrors the layout, and an overflow that leans on a physical direction
    // shows up in one direction only.
    //
    // The switcher lives in the sidebar footer, which on a phone is behind the toggle —
    // so getting to it is a tap first, which is worth knowing about the design as much
    // as it is a step in this test.
    test('keeps the page within the screen in Arabic too', async ({ page }) => {
        await page.goto('/counterparties');

        await page.getByRole('button', { name: 'Toggle Sidebar' }).first().click();
        await switchTo(page, 'ar');

        // Loaded fresh, so the open sheet is gone. While it is open the rest of the
        // page is aria-hidden, and nothing on it can be found or measured.
        await page.goto('/counterparties');

        await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible();

        const { scrollWidth, clientWidth } = await pageScrollsSideways(page);

        expect(scrollWidth).toBeLessThanOrEqual(clientWidth + 1);
    });
});
