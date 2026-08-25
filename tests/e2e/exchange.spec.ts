import { expect, test } from '@playwright/test';
import { CLIENT, grouped, signIn } from './support';

/**
 * Recording a currency exchange, through the browser.
 *
 * The one flow where the arithmetic, the interface and the ledger all have to agree.
 * Unit tests cover each of them; only this covers the path between them — that what a
 * person types produces the figures they are shown, and that those figures are what
 * gets written down.
 */
test.beforeEach(async ({ page }) => {
    await signIn(page);
    await page.goto('/exchange');
});

test('works the deal out from a rate, and shows the margin before recording it', async ({ page }) => {
    // The owner's own example: buying 50,000 USD, paying in EGP at 51.48.
    // Selling, not buying. The cost rate is per unit *delivered*, so a cost of 51.20
    // only means "what a dollar cost me" when the dollars are what leaves. Setting this
    // up as a purchase applies the rate to the pounds instead and values the deal at a
    // hundred and thirty million — which the first draft of this test did.
    await page.getByLabel('This deal').selectOption({ label: 'I am selling' });
    await page.getByLabel('I am selling', { exact: true }).fill('50000');
    await page.getByRole('combobox', { name: 'Currency' }).first().selectOption({ label: 'USD' });
    await page.getByRole('combobox', { name: 'Currency' }).last().selectOption({ label: 'EGP' });
    await page.getByLabel('Rate', { exact: true }).fill('51.48');

    // The counter amount is computed on the server: 50,000 × 51.48.
    await expect(page.getByLabel('Paid in')).toHaveValue('2574000.00', { timeout: 10_000 });

    // Cost 51.20, so the margin is 50,000 × 0.28.
    await page.getByLabel('Cost rate').fill('51.20');

    // Asserted against the panel rather than a single node: with no fees the figure is
    // both the gross and the net, so matching it exactly would be a strict-mode
    // violation rather than a stronger check.
    const calculation = page.getByRole('complementary');

    await expect(calculation).toContainText(grouped('14000.00'), { timeout: 10_000 });
    await expect(calculation).toContainText('Net profit');
    // The rate the ledger will record, derived from the two amounts rather than typed.
    await expect(calculation).toContainText('51.480000000000');
});

test('warns before recording a deal that loses money, and refuses until it is confirmed', async ({ page }) => {
    // Selling, not buying. The cost rate is per unit *delivered*, so a cost of 51.20
    // only means "what a dollar cost me" when the dollars are what leaves. Setting this
    // up as a purchase applies the rate to the pounds instead and values the deal at a
    // hundred and thirty million — which the first draft of this test did.
    await page.getByLabel('This deal').selectOption({ label: 'I am selling' });
    await page.getByLabel('I am selling', { exact: true }).fill('50000');
    await page.getByRole('combobox', { name: 'Currency' }).first().selectOption({ label: 'USD' });
    await page.getByRole('combobox', { name: 'Currency' }).last().selectOption({ label: 'EGP' });
    await page.getByLabel('Rate', { exact: true }).fill('51.48');
    await expect(page.getByLabel('Paid in')).toHaveValue('2574000.00', { timeout: 10_000 });

    // Bought at more than it was sold for.
    await page.getByLabel('Cost rate').fill('52.00');

    await expect(page.getByText('This deal loses money')).toBeVisible({ timeout: 10_000 });

    // The warning is enforced on the server, so submitting without ticking must fail.
    await page.getByRole('button', { name: 'Record exchange' }).click();

    await expect(page.getByText('Tick the confirmation before recording it')).toBeVisible();
});

test('records the deal and shows it on the client statement', async ({ page }) => {
    // Selling, not buying. The cost rate is per unit *delivered*, so a cost of 51.20
    // only means "what a dollar cost me" when the dollars are what leaves. Setting this
    // up as a purchase applies the rate to the pounds instead and values the deal at a
    // hundred and thirty million — which the first draft of this test did.
    await page.getByLabel('This deal').selectOption({ label: 'I am selling' });
    await page.getByLabel('I am selling', { exact: true }).fill('50000');
    await page.getByRole('combobox', { name: 'Currency' }).first().selectOption({ label: 'USD' });
    await page.getByRole('combobox', { name: 'Currency' }).last().selectOption({ label: 'EGP' });
    await page.getByLabel('Rate', { exact: true }).fill('51.48');
    await expect(page.getByLabel('Paid in')).toHaveValue('2574000.00', { timeout: 10_000 });

    await page.getByLabel('Cost rate').fill('51.20');
    await page.getByLabel('Counterparty').selectOption({ label: CLIENT });

    await page.getByRole('button', { name: 'Record exchange' }).click();

    await expect(page.getByText('Exchange recorded')).toBeVisible({ timeout: 10_000 });

    // And the ledger agrees: the transaction list shows both legs, in both currencies.
    await page.goto('/transactions');

    const row = page.getByRole('row').filter({ hasText: 'Currency exchange' }).first();
    await expect(row).toContainText(grouped('2574000.00'));
    await expect(row).toContainText(grouped('50000.00'));
});
