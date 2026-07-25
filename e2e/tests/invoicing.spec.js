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

  test("tracked time can be turned into a draft invoice from the UI", async ({ page }) => {
    await registerAndSignIn(page, uniqueEmail());

    // Seed the prerequisites over the API (page.request shares the signed-in
    // session). Clicking through client → project → categories → time entry
    // first would make this test mostly about *other* pages; what's under test
    // here is the invoice composer.
    const spaceIri = await personalSpaceIri(page);

    const client = await postJson(page, "/clients", {
      space: spaceIri,
      name: `Acme ${Date.now()}`,
      currency: "USD",
    });

    const project = await postJson(page, "/projects", {
      space: spaceIri,
      client: client["@id"],
      name: "Acme Website",
      currency: "USD",
      categories: [{ name: "Dev", billingRate: 6000, position: 0 }],
    });
    const devCategory = project.categories[0];

    // One billable hour → 6000 minor units.
    await postJson(page, "/time_entries", {
      space: spaceIri,
      project: project["@id"],
      category: devCategory["@id"],
      startedAt: "2026-07-03T09:00:00+00:00",
      endedAt: "2026-07-03T10:00:00+00:00",
    });

    // Now drive the composer: pick the project, preview, generate.
    await page.goto(`${BASE_URL}/invoices`);
    await page.getByRole("button", { name: /Generate invoice/ }).click();
    await page.locator("#gen-project").selectOption(project["@id"]);
    await page.getByRole("button", { name: /^Preview$/ }).click();

    // The preview lists the tracked hour, so Generate becomes available.
    const generate = page.getByRole("button", { name: /^Generate draft$/ });
    await expect(generate).toBeEnabled();
    await generate.click();

    // The success notice names the entry count, and the new draft appears in
    // the table under its client. (Asserting on the notice rather than any
    // text containing "draft" — the Generate button says that too.)
    await expect(
      page.getByText("Draft invoice created from 1 time entry."),
    ).toBeVisible();
    await expect(page.getByText(client.name)).toBeVisible();
  });
});

/** The signed-in user's personal space IRI — every seeded row hangs off it. */
async function personalSpaceIri(page) {
  const res = await page.request.get(`${BASE_URL}/spaces`, {
    headers: { Accept: "application/ld+json" },
  });
  const body = await res.json();
  const spaces = body.member ?? body["hydra:member"] ?? [];
  expect(spaces.length, "the user should have a personal space").toBeGreaterThan(0);
  return spaces[0]["@id"];
}

async function postJson(page, path, data) {
  const res = await page.request.post(`${BASE_URL}${path}`, {
    headers: { "Content-Type": "application/ld+json" },
    data,
  });
  expect(res.status(), `POST ${path} -> ${await res.text()}`).toBe(201);
  return res.json();
}
