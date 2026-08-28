import { expect, type Page, test } from '@playwright/test';
import { signIn, switchTo } from './support';

/**
 * Switching language, and what must survive it.
 *
 * Not merely that the words change: that the page you were looking at is still the page
 * you are looking at, with the same filters applied, laid out the other way round.
 */
test.beforeEach(async ({ page }) => {
    await signIn(page);

    // The language is stored against the user, so it outlives a test. Start from
    // English rather than from whatever the previous test chose.
    await switchTo(page, 'en');
});

/**
 * The sidebar itself, not merely something carrying data-side.
 *
 * Radix puts data-side on its own popover content, and since the language menu now
 * survives the switch a bare [data-side] matches two elements. data-variant is the
 * sidebar primitive's, and nothing else on the page has one.
 */
function sidebar(page: Page) {
    return page.locator('[data-variant][data-side]');
}

test('turns the whole interface over, sidebar included', async ({ page }) => {
    await page.goto('/dashboard');

    await expect(page.locator('html')).toHaveAttribute('dir', 'ltr');
    await expect(sidebar(page)).toHaveAttribute('data-side', 'left');

    await switchTo(page, 'ar');

    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');

    // The sidebar itself moves. Mirroring the text and leaving the furniture where it
    // was is the bug this asserts against — it was a real one, reported by the owner.
    await expect(sidebar(page)).toHaveAttribute('data-side', 'right');

    await expect(page.getByRole('heading', { name: 'لوحة المتابعة' })).toBeVisible();
});

test('keeps the filters you had applied', async ({ page }) => {
    await page.goto('/transactions?type=credit_deposit');

    const before = await page.getByRole('row').count();
    expect(before).toBeGreaterThan(1);

    await switchTo(page, 'ar');

    // Same page, same query string, same rows — in Arabic.
    await expect(page).toHaveURL(/type=credit_deposit/);
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
    // A retrying assertion, not a bare count(). The page component now survives the
    // switch instead of being rebuilt, so the rows are re-rendered rather than
    // remounted — and sampling the DOM the instant `lang` changes can catch it
    // mid-update. What is being asserted is unchanged.
    await expect(page.getByRole('row')).toHaveCount(before);

    await expect(page.getByRole('combobox').first()).toHaveValue('credit_deposit');
});

test('answers an Arabic operator in Arabic when they get something wrong', async ({ page }) => {
    // Validation messages were English for every Arabic user until the Arabic review;
    // the interface spoke Arabic and the error did not.
    await page.goto('/reconciliations');

    await switchTo(page, 'ar');

    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');

    // A count in the future is refused.
    const tomorrow = new Date(Date.now() + 86_400_000).toISOString().slice(0, 10);
    await page.getByLabel('بتاريخ').fill(tomorrow);
    await page.getByLabel('المجرود').fill('1000');
    await page.getByRole('button', { name: 'تسجيل جرد' }).click();

    // Arabic, not "The as of field must be a date before or equal to today."
    await expect(page.getByRole('alert').first()).toContainText(/[؀-ۿ]/);
});

/**
 * A half-entered deal survives the switch.
 *
 * The reason an operator reaches for the language menu mid-deal is to read a label they
 * are unsure of. Losing the amounts they had already typed is the worst possible answer
 * to that: it punishes exactly the person the second language is there for.
 *
 * The filters test above passes either way — filters live in the query string, and the
 * server redirects back to it. This is about the form state that only exists in the
 * browser.
 */
test('keeps what you had typed into a form', async ({ page }) => {
    await page.goto('/exchange');

    await page.getByLabel('This deal').selectOption({ label: 'I am selling' });
    await page.getByLabel('I am selling', { exact: true }).fill('50000');
    await page.getByRole('combobox', { name: 'Currency' }).first().selectOption({ label: 'USD' });
    await page.getByRole('combobox', { name: 'Currency' }).last().selectOption({ label: 'EGP' });
    await page.getByLabel('Rate', { exact: true }).fill('51.48');
    await page.getByLabel('Reference').fill('KEEP-ME');

    // The computed leg, which only exists because the two above were typed.
    await expect(page.getByLabel('Paid in')).toHaveValue('2574000.00', { timeout: 10_000 });

    await switchTo(page, 'ar');

    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');

    // Same figures, Arabic labels. Nothing was retyped.
    await expect(page.getByLabel('أنا أبيع', { exact: true })).toHaveValue('50000');
    await expect(page.getByLabel('القبض بـ')).toHaveValue('2574000.00');
    await expect(page.getByLabel('السعر', { exact: true })).toHaveValue('51.48');
    await expect(page.getByLabel('المرجع')).toHaveValue('KEEP-ME');
});

/**
 * A rate quotation is one formula, not three fragments.
 *
 * In Arabic the row itself flows right-to-left, so marking only the pieces as
 * left-to-right put the equals sign at the far right — against the label rather than
 * against the box — because "=" is the last character of "1 USD =" and therefore its
 * rightmost. Reported by the owner as "= 1 usd [number] eur".
 *
 * Asserting the structure rather than the pixels: the currency codes, the sign and the
 * input have to sit inside one left-to-right run.
 */
test('keeps a rate quotation in one direction', async ({ page }) => {
    await switchTo(page, 'ar');
    await page.goto('/exchange');

    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');

    for (const label of ['السعر', 'سعر التكلفة']) {
        const quotation = page.locator('[dir="ltr"]').filter({ has: page.getByLabel(label, { exact: true }) });

        await expect(quotation).toHaveCount(1);
        await expect(quotation).toContainText('1 USD =');
        await expect(quotation).toContainText('EUR');
    }
});

/**
 * The back button, after a language switch.
 *
 * Inertia restores a cached page without making a request, so the `success` event never
 * fires — and the html element kept whatever direction the last *request* had set.
 * React meanwhile re-rendered from the restored props, so the sidebar moved to the other
 * side while every logical property in the layout went on resolving the old way: a page
 * half turned over, which is what the owner saw.
 */
test('does not leave the page half turned over when you go back', async ({ page }) => {
    // Two pages visited in Arabic, so there is an Arabic entry to come back to. The
    // switch itself replaces its own history entry — it is a redirect to the same URL —
    // so it is the page before it that back restores.
    await switchTo(page, 'ar');
    await page.goto('/exchange');
    await page.goto('/dashboard');

    await switchTo(page, 'en');
    await expect(page.locator('html')).toHaveAttribute('dir', 'ltr');
    await expect(sidebar(page)).toHaveAttribute('data-side', 'left');

    // Back to the exchange, which was cached in Arabic. The sidebar follows the restored
    // props; before the fix `dir` did not, and the two disagreed.
    await page.goBack();

    await expect(page).toHaveURL(/\/exchange$/);
    await expect(sidebar(page)).toHaveAttribute('data-side', 'right');
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
});
