import { expect, test } from '@playwright/test';
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

test('turns the whole interface over, sidebar included', async ({ page }) => {
    await page.goto('/dashboard');

    await expect(page.locator('html')).toHaveAttribute('dir', 'ltr');
    await expect(page.locator('[data-side]')).toHaveAttribute('data-side', 'left');

    await switchTo(page, 'ar');

    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');

    // The sidebar itself moves. Mirroring the text and leaving the furniture where it
    // was is the bug this asserts against — it was a real one, reported by the owner.
    await expect(page.locator('[data-side]')).toHaveAttribute('data-side', 'right');

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
    expect(await page.getByRole('row').count()).toBe(before);

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
