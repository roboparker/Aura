// @ts-check
const { test, expect } = require("@playwright/test");

const BASE_URL = "https://localhost";

// Generate unique email per test run to avoid conflicts
const uniqueEmail = () => `test-${Date.now()}@example.com`;

test.describe("Authentication", () => {
  test("sign up with valid credentials", async ({ page }) => {
    const email = uniqueEmail();

    await page.goto(`${BASE_URL}/signup`);
    await expect(page).toHaveTitle("Sign Up - Aura");

    await page.fill("#givenName", "E2e");
    await page.fill("#familyName", "User");
    await page.fill("#email", email);
    await page.fill("#password", "password123");
    await page.fill("#confirmPassword", "password123");
    await page.click('button[type="submit"]');

    // Should redirect to sign-in with success message
    await expect(page).toHaveURL(/\/signin\?registered=true/);
    await expect(page.locator("text=Account created successfully")).toBeVisible();
  });

  test("sign up shows validation errors", async ({ page }) => {
    await page.goto(`${BASE_URL}/signup`);

    // Submit empty form
    await page.click('button[type="submit"]');
    await expect(page.locator("text=Email is required")).toBeVisible();
    await expect(page.locator("text=Password is required")).toBeVisible();
  });

  test("sign up shows password mismatch error", async ({ page }) => {
    await page.goto(`${BASE_URL}/signup`);

    await page.fill("#email", uniqueEmail());
    await page.fill("#password", "password123");
    await page.fill("#confirmPassword", "different");
    await page.click('button[type="submit"]');

    await expect(page.locator("text=Passwords do not match")).toBeVisible();
  });

  test("sign in with valid credentials", async ({ page }) => {
    // Register first via API
    const email = uniqueEmail();
    const res = await page.request.post(`${BASE_URL}/users`, {
      headers: { "Content-Type": "application/ld+json" },
      data: { email, plainPassword: "password123", givenName: "E2e", familyName: "User" },
    });
    expect(res.ok()).toBeTruthy();

    // Sign in via UI
    await page.goto(`${BASE_URL}/signin`);
    await expect(page).toHaveTitle("Sign In - Aura");

    await page.fill("#email", email);
    await page.fill("#password", "password123");
    await page.click('button[type="submit"]');

    // Should redirect to account page
    await expect(page).toHaveURL(/\/account/);
    await expect(page.locator(`text=${email}`)).toBeVisible();
  });

  test("sign in with invalid credentials shows error", async ({ page }) => {
    await page.goto(`${BASE_URL}/signin`);

    await page.fill("#email", "nonexistent@example.com");
    await page.fill("#password", "wrongpassword");
    await page.click('button[type="submit"]');

    await expect(page.locator("text=Invalid credentials")).toBeVisible();
  });

  test("sign in with fixture admin user accesses admin page", async ({ page }) => {
    await page.goto(`${BASE_URL}/signin`);
    await page.fill("#email", "admin@aura.test");
    await page.fill("#password", "admin123");
    await page.click('button[type="submit"]');

    await expect(page).toHaveURL(/\/account/);

    // Navigate to admin
    await page.goto(`${BASE_URL}/admin`);
    // Admin page should load (not show access denied)
    await expect(page.locator("text=Access Denied")).not.toBeVisible();
  });

  test("sign in with fixture standard user denied admin access", async ({ page }) => {
    await page.goto(`${BASE_URL}/signin`);
    await page.fill("#email", "user@aura.test");
    await page.fill("#password", "user123");
    await page.click('button[type="submit"]');

    await expect(page).toHaveURL(/\/account/);

    // Navigate to admin — should show access denied
    await page.goto(`${BASE_URL}/admin`);
    await expect(page.locator("text=Access Denied")).toBeVisible();
  });

  test("account page redirects unauthenticated users to sign in", async ({ page }) => {
    await page.goto(`${BASE_URL}/account`);
    await expect(page).toHaveURL(/\/signin/);
  });
});
