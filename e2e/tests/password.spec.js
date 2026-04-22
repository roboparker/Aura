// @ts-check
const { test, expect } = require("@playwright/test");

const BASE_URL = "https://localhost";
const MAILPIT_URL = process.env.MAILPIT_URL || "http://localhost:8025";

const uniqueEmail = () => `pw-test-${Date.now()}-${Math.floor(Math.random() * 10000)}@example.com`;

/**
 * Registers a user via the API.
 */
async function registerUser(request, email, password) {
  const res = await request.post(`${BASE_URL}/users`, {
    headers: { "Content-Type": "application/ld+json" },
    data: { email, plainPassword: password },
  });
  expect(res.ok()).toBeTruthy();
}

/**
 * Fetches Mailpit messages and returns the most recent email to `recipient`.
 * Polls for up to `timeout` ms.
 */
async function getLatestEmail(request, recipient, timeout = 5000) {
  const deadline = Date.now() + timeout;
  while (Date.now() < deadline) {
    const res = await request.get(`${MAILPIT_URL}/api/v1/messages?limit=50`);
    if (res.ok()) {
      const { messages = [] } = await res.json();
      const match = messages.find((m) =>
        (m.To || []).some((to) => to.Address === recipient)
      );
      if (match) {
        const detailRes = await request.get(
          `${MAILPIT_URL}/api/v1/message/${match.ID}`
        );
        if (detailRes.ok()) {
          return await detailRes.json();
        }
      }
    }
    await new Promise((resolve) => setTimeout(resolve, 250));
  }
  throw new Error(`No email to ${recipient} found within ${timeout}ms`);
}

/**
 * Clears all messages in Mailpit so tests don't cross-pollinate.
 */
async function clearMailpit(request) {
  await request.delete(`${MAILPIT_URL}/api/v1/messages`);
}

test.describe("Change password (authenticated)", () => {
  test("user can change their password and sign in with the new one", async ({
    page,
    request,
  }) => {
    const email = uniqueEmail();
    await registerUser(request, email, "originalpass");

    // Sign in with original password
    await page.goto(`${BASE_URL}/signin`);
    await page.fill("#email", email);
    await page.fill("#password", "originalpass");
    await page.click('button[type="submit"]');
    await expect(page).toHaveURL(/\/account/);

    // Change password
    await page.fill("#currentPassword", "originalpass");
    await page.fill("#newPassword", "brandnewpass");
    await page.fill("#confirmPassword", "brandnewpass");
    await page.click('button:has-text("Update Password")');

    await expect(
      page.locator('[data-testid="change-password-success"]')
    ).toBeVisible();

    // Sign out and sign back in with the new password
    await page.click('button:has-text("Sign Out")');
    await page.goto(`${BASE_URL}/signin`);
    await page.fill("#email", email);
    await page.fill("#password", "brandnewpass");
    await page.click('button[type="submit"]');
    await expect(page).toHaveURL(/\/account/);
  });

  test("wrong current password shows error", async ({ page, request }) => {
    const email = uniqueEmail();
    await registerUser(request, email, "originalpass");

    await page.goto(`${BASE_URL}/signin`);
    await page.fill("#email", email);
    await page.fill("#password", "originalpass");
    await page.click('button[type="submit"]');
    await expect(page).toHaveURL(/\/account/);

    await page.fill("#currentPassword", "wrongpass");
    await page.fill("#newPassword", "newpassword123");
    await page.fill("#confirmPassword", "newpassword123");
    await page.click('button:has-text("Update Password")');

    await expect(
      page.locator('[data-testid="change-password-error"]')
    ).toContainText(/current password is incorrect/i);
  });

  test("client-side validation for mismatched new passwords", async ({
    page,
    request,
  }) => {
    const email = uniqueEmail();
    await registerUser(request, email, "originalpass");

    await page.goto(`${BASE_URL}/signin`);
    await page.fill("#email", email);
    await page.fill("#password", "originalpass");
    await page.click('button[type="submit"]');
    await expect(page).toHaveURL(/\/account/);

    await page.fill("#currentPassword", "originalpass");
    await page.fill("#newPassword", "newpassword123");
    await page.fill("#confirmPassword", "different");
    await page.click('button:has-text("Update Password")');

    await expect(page.locator("text=Passwords do not match")).toBeVisible();
  });
});

test.describe("Forgot password (reset via email)", () => {
  test("full forgot-password flow: request reset, follow email link, set new password, sign in", async ({
    page,
    request,
  }) => {
    const email = uniqueEmail();
    await registerUser(request, email, "originalpass");

    await clearMailpit(request);

    // Request reset
    await page.goto(`${BASE_URL}/forgot-password`);
    await page.fill("#email", email);
    await page.click('button:has-text("Send Reset Link")');

    await expect(
      page.locator('[data-testid="forgot-password-success"]')
    ).toBeVisible();

    // Grab the reset link from Mailpit
    const message = await getLatestEmail(request, email);
    const body = message.Text || message.HTML || "";
    const match = body.match(/reset-password\?token=([a-f0-9]+)/);
    expect(match, "Reset link should be present in the email").toBeTruthy();
    const resetUrl = `${BASE_URL}/reset-password?token=${match[1]}`;

    // Visit reset link and set new password
    await page.goto(resetUrl);
    await page.fill("#newPassword", "brandnewpass");
    await page.fill("#confirmPassword", "brandnewpass");
    await page.click('button:has-text("Reset Password")');

    // Redirected to sign in with success banner
    await expect(page).toHaveURL(/\/signin\?reset=true/);
    await expect(
      page.locator('[data-testid="password-reset-success"]')
    ).toBeVisible();

    // Sign in with the new password
    await page.fill("#email", email);
    await page.fill("#password", "brandnewpass");
    await page.click('button[type="submit"]');
    await expect(page).toHaveURL(/\/account/);
  });

  test("forgot-password returns success for unknown email (no enumeration)", async ({
    page,
    request,
  }) => {
    await clearMailpit(request);

    await page.goto(`${BASE_URL}/forgot-password`);
    await page.fill("#email", `nobody-${Date.now()}@example.com`);
    await page.click('button:has-text("Send Reset Link")');

    await expect(
      page.locator('[data-testid="forgot-password-success"]')
    ).toBeVisible();
  });

  test("reset-password page without token shows error", async ({ page }) => {
    await page.goto(`${BASE_URL}/reset-password`);
    await expect(
      page.locator('[data-testid="reset-password-missing-token"]')
    ).toBeVisible();
  });

  test("reset-password page rejects an invalid token", async ({ page }) => {
    await page.goto(`${BASE_URL}/reset-password?token=not-a-real-token`);

    await page.fill("#newPassword", "brandnewpass");
    await page.fill("#confirmPassword", "brandnewpass");
    await page.click('button:has-text("Reset Password")');

    await expect(
      page.locator('[data-testid="reset-password-error"]')
    ).toContainText(/invalid or expired/i);
  });

  test("Forgot password link is visible on sign-in page", async ({ page }) => {
    await page.goto(`${BASE_URL}/signin`);
    await expect(page.locator("text=Forgot password?")).toBeVisible();
  });
});
