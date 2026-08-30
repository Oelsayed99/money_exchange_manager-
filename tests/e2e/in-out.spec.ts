import { expect, test } from '@playwright/test';
import { CLIENT, grouped, signIn } from './support';

/**
 * Money in and money out, and the one balance between them.
 *
 * There were nine movement types and four positions per party. Four of the nine posted
 * identical entries and were told apart only by a word stored on the transaction, and
 * the classification had to be made at the counter — before the operator necessarily
 * knew which one applied. What is left is In, Out, and a signed difference. See ADR
 * 0032.
 *
 * These walk the screens a clerk actually uses and check that the list, the panel and
 * the statement all agree at the end.
 */
test.beforeEach(async ({ page }) => {
    await signIn(page);
});

test('states which way the balance runs rather than leaving it to a minus sign', async ({ page }) => {
    await page.goto('/counterparties');
    await page
        .getByRole('row', { name: new RegExp(CLIENT) })
        .getByRole('link', { name: 'Statement' })
        .click();

    await expect(page).toHaveURL(/statement/);

    // The seeded deposits total 3,957,540 — the figure from the owner's own sheet.
    await expect(page.getByText(grouped('3957540.00')).first()).toBeVisible();

    // Said in words. The sheet this replaces used a signed column, and the sign was the
    // only thing distinguishing the two directions.
    await expect(page.getByText('we owe them').first()).toBeVisible();
});

test('records money going out and moves the balance by exactly that much', async ({ page }) => {
    await page.goto('/movements');

    await page.getByLabel('What happened').selectOption({ label: 'Out' });
    await page.getByLabel('Counterparty').selectOption({ label: CLIENT });
    // The currency defaults to the first in sort order rather than to whatever this
    // client is carrying, so it is chosen explicitly here.
    await page.getByLabel('Currency', { exact: true }).selectOption({ label: 'EGP' });
    await page.getByLabel('Amount', { exact: true }).fill('2574000');

    // The panel shows where they stand and what this movement would leave behind,
    // before anything is recorded. They are holding 3,957,540 of ours; paying out
    // 2,574,000 leaves 1,383,540.
    const standing = page.getByRole('complementary');
    await expect(standing).toContainText(grouped('3957540.00'), { timeout: 10_000 });
    await expect(standing).toContainText(grouped('1383540.00'));

    await page.getByRole('button', { name: 'Record movement' }).click();
    await expect(page.getByText('Movement recorded')).toBeVisible({ timeout: 10_000 });

    await page.goto('/counterparties');
    await page
        .getByRole('row', { name: new RegExp(CLIENT) })
        .getByRole('link', { name: 'Statement' })
        .click();

    await expect(page.getByText(grouped('1383540.00')).first()).toBeVisible();
});

/**
 * The change the owner asked for by name: take dollars, book pounds.
 *
 * "I got 10,000 USD but I want to save them as EGP, so I enter the exchange rate and
 * turn it to EGP — and the details still say it was 10,000 USD at 50.85."
 */
test('records a movement in one currency that arrived in another, and keeps both', async ({ page }) => {
    await page.goto('/movements');

    await page.getByLabel('What happened').selectOption({ label: 'In' });
    await page.getByLabel('Counterparty').selectOption({ label: 'Quiet Client' });
    await page.getByLabel('Currency', { exact: true }).selectOption({ label: 'EGP' });
    await page.getByLabel('Amount', { exact: true }).fill('508500');

    // What actually changed hands.
    await page.getByLabel('In currency').selectOption({ label: 'USD' });
    await page.getByLabel('Actually moved').fill('10000');
    await page.getByLabel('Rate').fill('50.85');

    // Worked out where the operator can see it before they commit.
    await expect(page.getByText('10000 USD @ 50.85 = 508500.00 EGP')).toBeVisible();

    await page.getByRole('button', { name: 'Record movement' }).click();
    await expect(page.getByText('Movement recorded')).toBeVisible({ timeout: 10_000 });

    // The client's balance moved in pounds...
    await page.goto('/counterparties');
    const row = page.getByRole('row').filter({ hasText: 'Quiet Client' });
    await expect(row).toContainText(grouped('508500.00'));
    await expect(row).toContainText('we owe them');

    // ...and the dollars are still on the record behind it.
    await row.getByRole('link', { name: 'Statement' }).click();
    await expect(page.getByText(`${grouped('10000.00')} USD at 50.85`).first()).toBeVisible();
});

/**
 * The dashboard's client table, which had no browser test at all.
 *
 * It went on reading a bucket list the backend had stopped sending, and threw on
 * render — a blank page, reported by the owner. Nothing here asserted the table.
 */
test('lists each client on the dashboard with one balance per currency', async ({ page }) => {
    await page.goto('/dashboard');

    await expect(page.getByRole('heading', { name: 'Dashboard' })).toBeVisible();

    await expect(page.getByRole('columnheader', { name: 'Balance' })).toBeVisible();

    // The figure itself rather than a fixed one — the tests above this move it. What
    // matters is that the table renders at all, and says which way the balance runs.
    const row = page.getByRole('row').filter({ hasText: CLIENT });

    await expect(row).toContainText(/[0-9],[0-9]{3}\.[0-9]{2}/);
    await expect(row).toContainText('we owe them');

    // The status beside it, in the vocabulary that replaced "they have credit".
    await expect(row).toContainText('We owe them');
});

test('says out loud when a movement turns the relationship over, and records it anyway', async ({ page }) => {
    await page.goto('/movements');

    await page.getByLabel('What happened').selectOption({ label: 'Out' });
    await page.getByLabel('Counterparty').selectOption({ label: CLIENT });
    await page.getByLabel('Currency', { exact: true }).selectOption({ label: 'USD' });
    await page.getByLabel('Amount', { exact: true }).fill('5000');

    // Nothing of theirs is held in dollars, so paying any out puts them in debt to us.
    // The owner's decision was to say so and allow it, never to block it.
    await expect(page.getByText('This turns the relationship over')).toBeVisible({ timeout: 10_000 });

    await page.getByRole('button', { name: 'Record movement' }).click();

    await expect(page.getByText('Movement recorded')).toBeVisible({ timeout: 10_000 });
});

/**
 * One column, and a way in to what is behind it.
 *
 * It carried four bucket columns, then two sides. The owner's objection to the two was
 * exact: a party cannot both owe us and be owed by us, it is one thing and its
 * difference.
 */
test('shows one balance per currency and opens the statement behind it', async ({ page }) => {
    await page.goto('/counterparties');

    const row = page.getByRole('row').filter({ hasText: CLIENT });

    await expect(page.getByRole('columnheader', { name: 'Balance' })).toBeVisible();
    await expect(page.getByRole('columnheader', { name: 'Our money with them' })).toBeHidden();
    await expect(page.getByRole('columnheader', { name: 'Their money with us' })).toBeHidden();
    await expect(page.getByRole('columnheader', { name: 'Credit held' })).toBeHidden();

    // The figure itself rather than a fixed one: the tests above this move it, and what
    // matters is that the list and the statement agree — not what they agree on.
    const figure = row.getByRole('link').filter({ hasText: /[0-9]/ }).first();
    const shown = ((await figure.innerText()) ?? '').replace(/[^0-9,.]/g, '').trim();

    expect(shown).not.toBe('');

    // The figure is the way in. Following it lands on that currency's statement, which
    // is where the movements behind it are.
    await figure.click();

    await expect(page).toHaveURL(/\/statement\?currency=EGP/);
    await expect(page.getByText(shown).first()).toBeVisible();
    await expect(page.getByRole('row').filter({ hasText: 'DEP-1' })).toBeVisible();
});
