// @ts-check
const { test, expect } = require("@playwright/test");
const { BASE_URL, uniqueEmail: shared, registerAndSignIn } = require("./helpers");

const uniqueEmail = () => shared("invoicing");

/**
 * Invoicing UI smoke (#598).
 *
 * The invoicing *behaviour* is covered thoroughly at the API level by
 * `App\Tests\Api\ClientInvoiceTest` (25 tests: generate from tracked time,
 * issue, send, public view, public pay + webhook, PDF, tax, discounts, partial
 * payments, recurring, overdue sweeps, reminders). What that can't tell us is
 * whether the PWA actually drives it — the gap #598 called out.
 *
 * This covers the entry point of the whole flow, which had no UI coverage at
 * all: creating a client, and the invoices page rendering for someone who may
 * bill. Deeper flow steps (project → tracked time → preview → generate) run
 * through several pages that carry no test ids yet; wiring those up is a
 * follow-up rather than a blind selector chain.
 */
test.describe("Invoicing", () => {
  test("unauthenticated visitors are redirected to sign in", async ({ page }) => {
    await page.goto(`${BASE_URL}/invoices`);
    await expect(page).toHaveURL(/\/signin/);
  });

  test("a client can be created from the clients page", async ({ page }) => {
    await registerAndSignIn(page, uniqueEmail());

    await page.goto(`${BASE_URL}/clients`);
    await expect(page.getByRole("heading", { name: "Clients" })).toBeVisible();

    const name = `Acme ${Date.now()}`;
    await page.getByRole("button", { name: /Add client/ }).click();
    await page.locator("#cl-name").fill(name);
    await page.locator("#cl-email").fill("billing@acme.test");
    await page.getByRole("button", { name: /^Create client$/ }).click();

    // The new client lands in the list — the row the invoice composer needs.
    await expect(page.getByText(name)).toBeVisible();

    // And it survives a reload, i.e. it was actually persisted rather than
    // just optimistically rendered.
    await page.reload();
    await expect(page.getByText(name)).toBeVisible();
  });

  test("the invoices page renders for a user who can bill", async ({ page }) => {
    await registerAndSignIn(page, uniqueEmail());

    await page.goto(`${BASE_URL}/invoices`);
    await expect(page.getByRole("heading", { name: "Invoices" })).toBeVisible();
  });
});
