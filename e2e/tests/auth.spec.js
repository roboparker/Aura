// @ts-check
const { test, expect } = require("@playwright/test");
const { BASE_URL, signInAsFixtureUser } = require("./helpers");

// Generate unique email per test run to avoid conflicts
const uniqueEmail = () => `test-${Date.now()}@example.com`;

test.describe("Authentication", () => {
  test("sign up with valid credentials", async ({ page }) => {
    const email = uniqueEmail();

    await page.goto(`${BASE_URL}/signup`);
    await expect(page).toHaveTitle("Sign Up - Madori");

    // Step 1 — form. The redesigned signup no longer has a confirm
    // field; the strength meter does the safety work instead.
    await page.fill("#givenName", "E2e");
    await page.fill("#familyName", "User");
    await page.fill("#email", email);
    await page.fill("#password", "Password123!@#");
    // Consent is now a required clickwrap checkbox before submit. Assert
    // it is checked before submitting so the Formik state has committed.
    const consent = page.getByRole("checkbox");
    await consent.click();
    await expect(consent).toBeChecked();
    // Move focus off the checkbox before submitting so the click is not
    // racing a blur/re-render from the Radix control.
    await consent.blur();
    await page.getByRole("button", { name: "Create account" }).click();

    // Step 2 — avatar color picker. Keep the random seed; just commit it.
    await expect(page.getByTestId("avatar-color-picker")).toBeVisible();
    await page.getByTestId("signup-color-continue").click();

    // Step 3 — provisioning animation then redirect to /signin with the
    // success banner. The animation runs ~3.2s — give it room.
    await expect(page).toHaveURL(/\/signin\?registered=true/, { timeout: 10_000 });
    await expect(page.locator("text=Account created successfully")).toBeVisible();
  });

  test("sign up shows validation errors", async ({ page }) => {
    await page.goto(`${BASE_URL}/signup`);

    // Submit empty form → top-of-form summary + inline messages.
    await page.getByRole("button", { name: "Create account" }).click();
    await expect(page.getByTestId("signup-error-summary")).toBeVisible();
    await expect(page.locator("text=Email is required")).toBeVisible();
    await expect(page.locator("text=Password is required")).toBeVisible();
  });

  test("sign in with valid credentials", async ({ page }) => {
    // Register first via API
    const email = uniqueEmail();
    const res = await page.request.post(`${BASE_URL}/users`, {
      headers: { "Content-Type": "application/ld+json" },
      data: { email, plainPassword: "Password123!@#", givenName: "E2e", familyName: "User" },
    });
    expect(res.ok()).toBeTruthy();

    // Sign in via UI
    await page.goto(`${BASE_URL}/signin`);
    await expect(page).toHaveTitle("Sign In - Madori");

    await page.fill("#email", email);
    await page.fill("#password", "Password123!@#");
    await page.click('button[type="submit"]');

    // A fresh sign-in with no redirect lands on the workspace home
    // (/projects) with the user's Private space active (#405).
    await expect(page).toHaveURL(/\/projects/);

    // Confirm we're signed in as the right account. The email appears in
    // two places (sidebar header + profile identity form), so open the
    // profile panel and scope the assertion to the main panel.
    await page.goto(`${BASE_URL}/settings/profile`);
    await expect(
      page.getByRole("main").getByText(email),
    ).toBeVisible();
  });

  test("sign in with invalid credentials shows error", async ({ page }) => {
    await page.goto(`${BASE_URL}/signin`);

    await page.fill("#email", "nonexistent@example.com");
    await page.fill("#password", "wrongpassword");
    await page.click('button[type="submit"]');

    await expect(page.locator("text=Invalid email or password")).toBeVisible();
  });

  test("sign in with fixture admin user accesses admin page", async ({ page }) => {
    await page.goto(`${BASE_URL}/signin`);
    await page.fill("#email", "admin@madori.test");
    await page.fill("#password", "admin123");
    await page.click('button[type="submit"]');

    // Fresh sign-in lands on the workspace home (#405).
    await expect(page).toHaveURL(/\/projects/);

    // Navigate to admin
    await page.goto(`${BASE_URL}/admin`);
    // Admin page should load (not show access denied)
    await expect(page.locator("text=Access Denied")).not.toBeVisible();
  });

  test("sign in with fixture standard user denied admin access", async ({ page }) => {
    // Uma has 2FA pre-enabled in fixtures so the standard sign-in path
    // exercises the challenge step on every reload — see
    // `App\DataFixtures\UserFixtures`. The helper walks both steps.
    await signInAsFixtureUser(page);

    // Navigate to admin — should show access denied
    await page.goto(`${BASE_URL}/admin`);
    await expect(page.locator("text=Access Denied")).toBeVisible();
  });

  test("account page redirects unauthenticated users to sign in", async ({ page }) => {
    await page.goto(`${BASE_URL}/account`);
    await expect(page).toHaveURL(/\/signin/);
  });
});
