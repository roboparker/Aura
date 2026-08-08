// @ts-check
const { test, expect } = require("@playwright/test");
const { BASE_URL } = require("./helpers");

// Public plans & pricing page. The tier cards and the comparison table both
// render from `pwa/lib/planTiers.ts` (which mirrors App\Billing\PlanCatalog);
// planTiers.test.ts covers that matrix as data, so what's worth guarding here
// is that it actually reaches the page — the table markup builds its rows with
// a flatMap of group-header + feature rows, and the prices in both the cards
// and the table header have to follow the monthly/annual toggle together.
test.describe("Plans & pricing", () => {
  test("lists every plan with a comparison table", async ({ page }) => {
    await page.goto(`${BASE_URL}/pricing`);

    for (const plan of ["Free", "Pro", "Business", "Enterprise"]) {
      await expect(
        page.getByRole("heading", { name: plan, exact: true }),
      ).toBeVisible();
    }

    const table = page.locator("table");
    await expect(table).toBeVisible();

    // One header cell per plan, plus the leading "Feature" column.
    await expect(table.locator("thead th")).toHaveCount(5);

    // A representative gated row: Free doesn't get automations, Business does.
    const row = table.locator("tr", { hasText: "Board automations" }).first();
    await expect(row).toBeVisible();
    await expect(row.getByText("Not included").first()).toBeAttached();
    await expect(row.getByText("Included").first()).toBeAttached();
  });

  test("the annual toggle repriced both the cards and the table", async ({ page }) => {
    await page.goto(`${BASE_URL}/pricing`);

    // Monthly is the default.
    await expect(page.getByText("$10", { exact: false }).first()).toBeVisible();

    await page.getByRole("button", { name: /Annual/ }).click();

    // Annual bills at ten months — Pro $10/mo becomes $100/yr. It appears on
    // the card and again under the plan's table column header.
    await expect(page.getByText("$100").first()).toBeVisible();
    await expect(page.locator("thead").getByText("$100 per year")).toBeVisible();
  });
});
