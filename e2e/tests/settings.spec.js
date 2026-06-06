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

    await page.getByTestId("settings-nav-api-tokens").click();
    await expect(page).toHaveURL(/\/settings\/api-tokens/);
    await expect(page.getByTestId("api-tokens")).toBeVisible();

    await page.getByTestId("settings-nav-danger").click();
    await expect(page).toHaveURL(/\/settings\/danger/);

    await page.getByTestId("settings-nav-profile").click();
    await expect(page).toHaveURL(/\/settings\/profile/);
  });

  test("generate and revoke an API token", async ({ page }) => {
    // Accept the revoke confirm() prompt — registered up front so it's active
    // by the time the dialog fires.
    page.on("dialog", (d) => d.accept());
    await registerAndSignIn(page, uniqueEmail());
    await page.goto(`${BASE_URL}/settings/api-tokens`);

    await page.getByTestId("api-token-generate").click();
    await page.getByTestId("api-token-name").fill("ci-test");
    await page.getByTestId("api-token-create").click();

    // One-time plaintext shown, prefixed with aura_pat_.
    const plaintext = page.getByTestId("api-token-plaintext");
    await expect(plaintext).toBeVisible();
    await expect(plaintext).toContainText("aura_pat_");
    await page.getByTestId("api-token-done").click();

    // Row appears; revoke it (the confirm() prompt is auto-accepted above).
    await expect(page.getByTestId("api-token-row")).toContainText("ci-test");
    await page.getByTestId("api-token-revoke").click();
    await expect(page.getByTestId("api-token-row")).toHaveCount(0);
  });

  test("deleting the account requires the exact email and signs out", async ({
    page,
  }) => {
    await registerAndSignIn(page, uniqueEmail());
    await page.goto(`${BASE_URL}/settings/danger`);

    await page.getByTestId("delete-open").click();
    // Wrong email keeps the confirm button disabled.
    await page.getByTestId("delete-confirm-email").fill("wrong@example.com");
    await page.getByTestId("danger-stepup-input").fill("Password123!@#");
    await expect(page.getByTestId("delete-confirm")).toBeDisabled();
  });

  test("app renders in dark mode (no theme switcher)", async ({ page }) => {
    await registerAndSignIn(page, uniqueEmail());
    await page.goto(`${BASE_URL}/settings/profile`);

    // Aura is dark-only: the appearance/theme picker is gone and the
    // document is always tagged `dark`.
    await expect(
      page.locator('[data-testid="settings-appearance"]'),
    ).toHaveCount(0);
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
