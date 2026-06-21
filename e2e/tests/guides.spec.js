// @ts-check
const { test, expect } = require("@playwright/test");
const { BASE_URL } = require("./helpers");

// Public docs surface (/guides) rendered from the repo-root `docs/` tree,
// staged into the PWA build by scripts/sync-docs.mjs. Guards the build wiring:
// if the docs aren't staged before the prod image build, getAllDocs() is empty,
// the index has no links and the slug pages 404 — so this fails.
test.describe("Guides (public docs)", () => {
  test("index lists guides and a guide page renders", async ({ page }) => {
    await page.goto(`${BASE_URL}/guides`);

    const link = page.locator('a[href^="/guides/"]').first();
    await expect(link).toBeVisible();
    const href = await link.getAttribute("href");
    expect(href).toBeTruthy();

    await page.goto(`${BASE_URL}${href}`);
    // The markdown rendered into a real page (not a 404).
    await expect(page.locator("main h1, article h1, h1").first()).toBeVisible();
  });
});
