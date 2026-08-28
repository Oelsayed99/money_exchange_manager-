import { expect, test } from '@playwright/test';
import { CLIENT, grouped, signIn } from './support';

/**
 * Money left with the business, and money paid back out of it.
 *
 * The flow the whole four-bucket model exists for: a client's credit is their money in
 * our keeping, not a balance we can net against what they owe. This walks the screens a
 * clerk actually uses and checks the statement agrees at the end.
 */
test.beforeEach(async ({ page }) => {
    await signIn(page);
});

test('shows a client the credit they have left with us, labelled rather than signed', async ({ page }) => {
    await page.goto('/counterparties');
    await page
        .getByRole('row', { name: new RegExp(CLIENT) })
        .getByRole('link', { name: 'Statement' })
        .click();

    await expect(page).toHaveURL(/statement/);

    // The seeded deposits total 3,957,540 — the figure from the owner's own sheet.
    await expect(page.getByText(grouped('3957540.00')).first()).toBeVisible();

    // Stated in words. The sheet this replaces used a signed column, and the sign was
    // the only thing distinguishing "they are holding our money" from "they owe us".
    await expect(page.getByText('Client credit with us').first()).toBeVisible();
});

test('records a settlement and moves the position by exactly that much', async ({ page }) => {
    await page.goto('/movements');

    await page.getByLabel('What happened').selectOption({ label: 'Credit settlement' });
    await page.getByLabel('Counterparty').selectOption({ label: CLIENT });
    // The currency defaults to the first in sort order, not to whatever this client
    // holds — so a client whose money is all in pounds shows four zeros until it is
    // changed. Chosen explicitly here rather than relying on the default.
    await page.getByLabel('Currency').selectOption({ label: 'EGP' });
    await page.getByLabel('Amount').fill('2574000');

    // The panel shows the four positions and what this movement would leave behind,
    // before anything is recorded.
    const positions = page.getByRole('complementary');
    await expect(positions).toContainText(grouped('3957540.00'), { timeout: 10_000 });
    await expect(positions).toContainText(grouped('1383540.00'));

    await page.getByRole('button', { name: 'Record movement' }).click();
    await expect(page.getByText('Movement recorded')).toBeVisible({ timeout: 10_000 });

    // And the statement agrees: 3,957,540 in, 2,574,000 out, 1,383,540 left.
    await page.goto('/counterparties');
    await page
        .getByRole('row', { name: new RegExp(CLIENT) })
        .getByRole('link', { name: 'Statement' })
        .click();

    await expect(page.getByText(grouped('1383540.00')).first()).toBeVisible();
});

test('warns when paying out more than the client left, and records it anyway', async ({ page }) => {
    await page.goto('/movements');

    await page.getByLabel('What happened').selectOption({ label: 'Credit settlement' });
    await page.getByLabel('Counterparty').selectOption({ label: 'Quiet Client' });
    await page.getByLabel('Currency').selectOption({ label: 'EGP' });
    await page.getByLabel('Amount').fill('5000');

    // This client has left nothing, so any settlement takes them negative. The owner's
    // decision was to allow it with a warning, never to block it.
    await expect(page.getByText('This takes the balance below zero')).toBeVisible({ timeout: 10_000 });

    await page.getByRole('button', { name: 'Record movement' }).click();

    await expect(page.getByText('Movement recorded')).toBeVisible({ timeout: 10_000 });
});
