// @ts-check
const { test, expect } = require("@playwright/test");
const {
  BASE_URL,
  uniqueEmail: shared,
  registerAndSignIn,
} = require("./helpers");

const uniqueEmail = () => shared("nav-overdue");

test.describe("Navbar overdue badge", () => {
  test("hidden when there are no overdue tasks", async ({ page }) => {
    await registerAndSignIn(page, uniqueEmail());
    // The badge polls /tasks?overdue=true on mount; with a fresh user there
    // is nothing overdue so the chip stays unrendered.
    await expect(
      page.locator('[data-testid="navbar-overdue-badge"]'),
    ).toHaveCount(0);
  });

  test("appears with the overdue count and links to the tasks page", async ({
    page,
  }) => {
    await registerAndSignIn(page, uniqueEmail());

    // Seed two overdue tasks via the API so the badge has something to count.
    // The page itself doesn't matter — the badge is part of the navbar and
    // shows on every authenticated route.
    const yesterday = new Date();
    yesterday.setDate(yesterday.getDate() - 1);
    yesterday.setHours(0, 0, 0, 0);

    for (let i = 0; i < 2; i += 1) {
      const createRes = await page.request.post(`${BASE_URL}/tasks`, {
        headers: { "Content-Type": "application/ld+json" },
        data: { title: `Late ${i} ${Date.now()}` },
      });
      expect(createRes.ok()).toBeTruthy();
      const created = await createRes.json();
      const patchRes = await page.request.patch(
        `${BASE_URL}${created["@id"]}`,
        {
          headers: { "Content-Type": "application/merge-patch+json" },
          data: { dueDate: yesterday.toISOString() },
        },
      );
      expect(patchRes.ok()).toBeTruthy();
    }

    // A reload fires the badge fetch immediately rather than waiting on the
    // 30s poll interval.
    await page.reload();

    const badge = page.locator('[data-testid="navbar-overdue-badge"]');
    await expect(badge).toBeVisible();
    await expect(
      page.locator('[data-testid="navbar-overdue-count"]'),
    ).toHaveText("2");

    await badge.click();
    await expect(page).toHaveURL(/\/tasks$/);
  });
});
