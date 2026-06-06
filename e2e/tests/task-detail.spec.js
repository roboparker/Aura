// @ts-check
const { test, expect } = require("@playwright/test");
const {
  BASE_URL,
  uniqueEmail: shared,
  registerAndSignIn,
  createTaskInline,
} = require("./helpers");

const uniqueEmail = () => shared("task-detail");

test.describe("Task detail drawer", () => {
  test("opens from a row, deep-links via the URL, and survives reload", async ({
    page,
  }) => {
    await registerAndSignIn(page, uniqueEmail());
    await page.goto(`${BASE_URL}/tasks`);

    const title = `Detail task ${Date.now()}`;
    await createTaskInline(page, title);

    const item = page.locator('[data-testid="task-item"]', { hasText: title });
    await expect(item).toBeVisible();

    // Open the drawer from the row's open-detail button.
    await item.locator('[data-testid="task-open-detail"]').click();

    const drawer = page.locator('[data-testid="task-detail-drawer"]');
    await expect(drawer).toBeVisible();
    await expect(drawer.locator('[data-testid="task-detail-title"]')).toHaveValue(
      title,
    );

    // Deep-link: the open task is reflected in the URL.
    await expect(page).toHaveURL(/[?&]task=/);

    // Refresh-safe: reloading the deep link re-opens the drawer.
    await page.reload();
    await expect(
      page.locator('[data-testid="task-detail-drawer"]'),
    ).toBeVisible();

    // The three tabs are present.
    await expect(page.locator('[data-testid="task-tab-details"]')).toBeVisible();
    await expect(page.locator('[data-testid="task-tab-activity"]')).toBeVisible();
    await expect(page.locator('[data-testid="task-tab-comments"]')).toBeVisible();

    // Closing the drawer (Escape) removes the deep-link param.
    await page.keyboard.press("Escape");
    await expect(
      page.locator('[data-testid="task-detail-drawer"]'),
    ).toHaveCount(0);
    await expect(page).not.toHaveURL(/[?&]task=/);
  });
});
