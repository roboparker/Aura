// @ts-check
const { test, expect } = require("@playwright/test");
const {
  BASE_URL,
  computeTotpCode,
  registerAndSignIn,
  uniqueEmail,
} = require("./helpers");

test.describe("Two-factor authentication", () => {
  test("opens setup dialog with QR code and rejects bad codes", async ({ page }) => {
    const email = uniqueEmail("tfa-setup");
    await registerAndSignIn(page, email);

    // /account hosts the Security card with the 2FA section.
    await page.goto(`${BASE_URL}/account`);

    const section = page.getByTestId("2fa-section");
    await expect(section).toBeVisible();
    await expect(section.getByText("Two-factor authentication")).toBeVisible();

    await page.getByTestId("2fa-enable-button").click();

    const dialog = page.getByTestId("2fa-setup-dialog");
    await expect(dialog).toBeVisible();
    // QR + the manual-entry secret should both be present so users with a
    // broken phone camera have a fallback path.
    await expect(dialog.getByTestId("2fa-qr-code")).toBeVisible();
    await expect(dialog.getByTestId("2fa-secret")).toBeVisible();

    // A wrong code must be rejected without enabling 2FA.
    await page.getByTestId("2fa-verify-code").fill("000000");
    await dialog.getByRole("button", { name: /verify and enable/i }).click();
    await expect(dialog.getByText(/invalid code/i)).toBeVisible();
  });

  test("login with 2FA enabled prompts for a code on next sign-in", async ({ page, request }) => {
    const email = uniqueEmail("tfa-login");
    const password = "Password123!";
    await registerAndSignIn(page, email, password);

    // Drive the API directly to fully enable 2FA — the UI verify step
    // needs a real TOTP code which is hard to compute in browser tests.
    // From the user's perspective the result is the same: the next
    // /signin must render the code-entry step.
    const setupRes = await page.request.post(`${BASE_URL}/me/2fa/setup`);
    expect(setupRes.ok()).toBeTruthy();
    const { secret } = await setupRes.json();
    const code = await computeTotpCode(secret);
    const verifyRes = await page.request.post(`${BASE_URL}/me/2fa/verify`, {
      data: { code },
      headers: { "Content-Type": "application/json" },
    });
    expect(verifyRes.ok()).toBeTruthy();

    // Sign back in — the password step should hand off to the 2FA step.
    await page.request.post(`${BASE_URL}/auth/logout`);
    await page.goto(`${BASE_URL}/signin`);
    await page.fill("#email", email);
    await page.fill("#password", password);
    await page.click('button[type="submit"]');

    await expect(page.getByText("Two-factor authentication")).toBeVisible();
    await expect(page.getByTestId("2fa-code")).toBeVisible();
  });
});

