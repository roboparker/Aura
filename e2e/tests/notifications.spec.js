// @ts-check
const { test, expect } = require("@playwright/test");
const {
  BASE_URL,
  uniqueEmail: shared,
  registerAndSignIn,
} = require("./helpers");

const uniqueEmail = () => shared("notifications");

test.describe("Notifications", () => {
  test("authenticated users see the notification bell with a zero state", async ({ page }) => {
    await registerAndSignIn(page, uniqueEmail());
    // Land on the account page (registerAndSignIn redirects there) — the
    // bell is part of the navbar and is visible on every authenticated route.
    const bell = page.locator('[data-testid="notification-bell"]');
    await expect(bell).toBeVisible();
    // No notifications yet → no count badge rendered.
    await expect(
      page.locator('[data-testid="notification-bell-count"]'),
    ).toHaveCount(0);

    await bell.click();
    await expect(
      page.locator('[data-testid="notification-bell-panel"]'),
    ).toContainText("You're all caught up.");
  });
});
