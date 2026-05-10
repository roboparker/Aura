// @ts-check
const { test, expect } = require("@playwright/test");
const {
  BASE_URL,
  uniqueEmail: shared,
  registerAndSignIn,
} = require("./helpers");

const uniqueEmail = () => shared("settings");

test.describe("Settings", () => {
  test("unauthenticated visitors are redirected to sign in", async ({ page }) => {
    await page.goto(`${BASE_URL}/settings`);
    await expect(page).toHaveURL(/\/signin/);
  });

  test("user can change theme and notification preferences and they persist", async ({
    page,
  }) => {
    await registerAndSignIn(page, uniqueEmail());
    await page.goto(`${BASE_URL}/settings`);
    await expect(page).toHaveTitle("Settings - Aura");

    // Defaults: system theme + realtime frequency + email on, push off.
    const systemTheme = page.locator('[data-testid="settings-theme-system"]');
    await expect(systemTheme).toHaveAttribute("aria-checked", "true");
    await expect(
      page.locator('[data-testid="settings-email-toggle"]'),
    ).toBeChecked();
    await expect(
      page.locator('[data-testid="settings-push-toggle"]'),
    ).not.toBeChecked();
    await expect(
      page.locator('[data-testid="settings-frequency-realtime"]'),
    ).toHaveAttribute("aria-checked", "true");

    // Switch to dark — settings auto-save.
    await page.locator('[data-testid="settings-theme-dark"]').click();
    await expect(
      page.locator('[data-testid="settings-theme-dark"]'),
    ).toHaveAttribute("aria-checked", "true");
    // Wait for the "Saved" toast as confirmation that the PATCH landed.
    await expect(page.locator('[data-testid="settings-save-saved"]')).toBeVisible();

    // Disable email + flip frequency to daily.
    await page.locator('[data-testid="settings-email-toggle"]').uncheck();
    await page.locator('[data-testid="settings-frequency-daily"]').click();
    await expect(
      page.locator('[data-testid="settings-frequency-daily"]'),
    ).toHaveAttribute("aria-checked", "true");

    // Reload — preferences came from the server, not just local state.
    await page.reload();
    await expect(page.locator('[data-testid="settings-theme-dark"]')).toHaveAttribute(
      "aria-checked",
      "true",
    );
    await expect(
      page.locator('[data-testid="settings-email-toggle"]'),
    ).not.toBeChecked();
    await expect(
      page.locator('[data-testid="settings-frequency-daily"]'),
    ).toHaveAttribute("aria-checked", "true");

    // Theme is also reflected on the <html> element via next-themes.
    const htmlClass = await page.locator("html").getAttribute("class");
    expect(htmlClass).toContain("dark");
  });

  test("settings link appears in the sidebar", async ({ page }) => {
    await registerAndSignIn(page, uniqueEmail());
    // No "open menu" step — the sidebar is always visible on the
    // desktop viewport Playwright uses.
    const settingsLink = page
      .locator('[data-testid="app-sidebar"]')
      .getByRole("link", { name: "Settings" });
    await expect(settingsLink).toBeVisible();
    await settingsLink.click();
    await expect(page).toHaveURL(/\/settings$/);
  });
});
