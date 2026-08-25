import { expect, test } from '@playwright/test';
import { CLIENT, grouped, signIn, switchTo } from './support';

/**
 * What a client's copy must never contain.
 *
 * The unit tests assert this at the query and the serialisation layer. This asserts it
 * where it actually matters — in the bytes of the file that gets sent to somebody, and
 * in the source of the page it was generated from.
 *
 * Inertia writes its props into the HTML document, so "the component does not render
 * it" is not the same as "it is not there". A margin that reaches a client cannot be
 * taken back.
 *
 * The margin comes from the seeder rather than from an exchange recorded here: an
 * exchange settled in cash touches no counterparty account (ADR 0009), so it never
 * appears on a client's statement and could not demonstrate anything being hidden from
 * one. The first draft of this test made that mistake.
 */
const MARGIN = '14000.00';

test.beforeEach(async ({ page }) => {
    await signIn(page);

    // The language is stored against the user and survives from one spec file to the
    // next, and these tests read column headings. Set it rather than inherit it.
    await switchTo(page, 'en');
});

test('shows the margin in my own copy and not in the client’s', async ({ page }) => {
    await page.goto(`/counterparties/1/statement?currency=EGP&mode=internal`);
    await expect(page.getByText('Margin earned')).toBeVisible();
    await expect(page.getByText(grouped(MARGIN)).first()).toBeVisible();

    await page.goto(`/counterparties/1/statement?currency=EGP&mode=client`);
    await expect(page.getByText('Margin earned')).toBeHidden();

    // Not merely unrendered: absent from the document Inertia serialised its props into.
    const source = await page.content();
    expect(source).not.toContain(MARGIN);
    expect(source).not.toContain(grouped(MARGIN));
});

test('leaves no margin in the CSV a client is sent', async ({ page }) => {
    const client = await page.request.get('/counterparties/1/statement/csv?currency=EGP&mode=client');
    const internal = await page.request.get('/counterparties/1/statement/csv?currency=EGP&mode=internal');

    expect(client.status()).toBe(200);

    const clientCsv = await client.text();
    const internalCsv = await internal.text();

    // The paired positive: without it this would pass just as well if the figure were
    // missing from both, which would prove nothing.
    expect(internalCsv).toContain(MARGIN);
    expect(clientCsv).not.toContain(MARGIN);

    expect(clientCsv).not.toContain('Profit');
    expect(internalCsv).toContain('Profit');

    // And the file is still the client's statement, with the figures they are owed.
    // Asserted against the seeded total rather than anything another spec records, so
    // this test means the same run alone as it does in the suite.
    expect(clientCsv).toContain('3957540.00');
    // A byte-order mark, or Excel renders the Arabic name as rubbish.
    expect(clientCsv.charCodeAt(0)).toBe(0xfeff);
});

test('leaves no margin in the PDF a client is handed', async ({ page }) => {
    const client = await page.request.get('/counterparties/1/statement/pdf?currency=EGP&mode=client');

    expect(client.status()).toBe(200);
    expect(client.headers()['content-type']).toContain('application/pdf');

    const bytes = await client.body();

    expect(bytes.subarray(0, 5).toString()).toBe('%PDF-');
    expect(bytes.length).toBeGreaterThan(2000);
});

test('refuses a client copy to nobody at all', async ({ browser }) => {
    // A statement is only ever reachable by somebody signed in, whatever the mode says.
    const anonymous = await browser.newContext();
    const response = await anonymous.request.get('/counterparties/1/statement/csv?mode=internal', {
        maxRedirects: 0,
    });

    expect(response.status()).toBe(302);
    expect(response.headers()['location']).toContain('/login');

    await anonymous.close();
});
