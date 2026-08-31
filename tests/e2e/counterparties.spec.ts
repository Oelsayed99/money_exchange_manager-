import { expect, test } from '@playwright/test';
import { grouped, signIn, switchTo } from './support';

/**
 * A position typed on a counterparty is a transaction.
 *
 * It used to be a note on the record — no date, nothing in the transaction list, and a
 * warning on the statement admitting the figure was not in the books. Recording it is
 * the whole point of typing it.
 */
test.beforeEach(async ({ page }) => {
    await signIn(page);
    await switchTo(page, 'en');
});

test('records an opening position, and it turns up in the transactions', async ({ page }) => {
    await page.goto('/counterparties/create');

    await page.getByLabel('Name').fill('Opening Test Client');

    // One figure per currency, and it may be negative. Negative is what an opening
    // usually is: money of theirs we were already holding when the books started.
    await page.getByLabel('EGP', { exact: true }).fill('-250000');

    await page.getByRole('button', { name: 'Save' }).click();

    await expect(page).toHaveURL(/\/counterparties$/);

    // It is a position now, not a note: the list carries it as a balance, worded.
    const row = page.getByRole('row').filter({ hasText: 'Opening Test Client' });
    await expect(row).toContainText(grouped('250000.00'));
    await expect(row).toContainText('we owe them');
    await expect(row).not.toContainText('Opening position not posted');

    // And it is in the ledger, with a date.
    await page.goto('/transactions');
    const entry = page.getByRole('row').filter({ hasText: 'Opening Test Client' }).first();

    await expect(entry).toBeVisible();
    await expect(entry).toContainText(grouped('250000.00'));
});
