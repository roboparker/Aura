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
    await page.goto(`${BASE_URL}/settings/profile`);
    await expect(page).toHaveURL(/\/signin/);
  });

  test("old /account and /settings routes redirect into the shell", async ({
    page,
  }) => {
    await registerAndSignIn(page, uniqueEmail());

    await page.goto(`${BASE_URL}/account`);
    await expect(page).toHaveURL(/\/settings\/profile/);

    await page.goto(`${BASE_URL}/settings`);
    await expect(page).toHaveURL(/\/settings\/profile/);
  });

  test("subnav moves between Profile, Security and Notifications", async ({
    page,
  }) => {
    await registerAndSignIn(page, uniqueEmail());
    await page.goto(`${BASE_URL}/settings/profile`);

    await page.getByTestId("settings-nav-security").click();
    await expect(page).toHaveURL(/\/settings\/security/);
    await expect(page.getByTestId("2fa-section")).toBeVisible();

    await page.getByTestId("settings-nav-notifications").click();
    await expect(page).toHaveURL(/\/settings\/notifications/);
    await expect(page.getByTestId("settings-notifications")).toBeVisible();

    await page.getByTestId("settings-nav-profile").click();
    await expect(page).toHaveURL(/\/settings\/profile/);
  });

  test("theme preference persists (Profile panel)", async ({ page }) => {
    await registerAndSignIn(page, uniqueEmail());
    await page.goto(`${BASE_URL}/settings/profile`);

    await expect(
      page.locator('[data-testid="settings-theme-system"]'),
    ).toHaveAttribute("aria-checked", "true");

    await page.locator('[data-testid="settings-theme-dark"]').click();
    await expect(page.locator('[data-testid="settings-save-saved"]')).toBeVisible();

    await page.reload();
    await expect(
      page.locator('[data-testid="settings-theme-dark"]'),
    ).toHaveAttribute("aria-checked", "true");
    const htmlClass = await page.locator("html").getAttribute("class");
    expect(htmlClass).toContain("dark");
  });

  test("notification matrix preference persists (Notifications panel)", async ({
    page,
  }) => {
    await registerAndSignIn(page, uniqueEmail());
    await page.goto(`${BASE_URL}/settings/notifications`);

    // Mentions → email is on by default; turn it off.
    const mentionsEmail = page.getByTestId("notif-mentions-email");
    await expect(mentionsEmail).toHaveAttribute("aria-checked", "true");
    await mentionsEmail.click();
    await expect(mentionsEmail).toHaveAttribute("aria-checked", "false");
    // Wait for the autosave to land before reloading.
    await expect(page.getByTestId("settings-save-saved")).toBeVisible();

    await page.reload();
    await expect(page.getByTestId("notif-mentions-email")).toHaveAttribute(
      "aria-checked",
      "false",
    );
  });

  test("time zone selection persists across reload", async ({ page }) => {
    await registerAndSignIn(page, uniqueEmail());
    await page.goto(`${BASE_URL}/settings/profile`);

    const tz = page.getByTestId("settings-timezone-input");
    await tz.click();
    await tz.fill("London");
    await page.getByRole("option", { name: /London/ }).first().click();

    await page.reload();
    await expect(page.getByTestId("settings-timezone-input")).toHaveValue(
      /London/,
    );
  });

  test("settings link in the sidebar opens the shell", async ({ page }) => {
    await registerAndSignIn(page, uniqueEmail());
    const settingsLink = page
      .locator('[data-testid="app-sidebar"]')
      .getByRole("link", { name: "Settings" });
    await expect(settingsLink).toBeVisible();
    await settingsLink.click();
    await expect(page).toHaveURL(/\/settings\/profile/);
  });
});
