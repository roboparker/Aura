// @ts-check
const { test, expect } = require("@playwright/test");
const { BASE_URL, uniqueEmail } = require("./helpers");

test.describe("Auth redirect", () => {
  test("unauthenticated visit to a restricted page lands on /signin with ?next, then comes back after login", async ({
    page,
    request,
  }) => {
    const email = uniqueEmail("redirect");
    const password = "password123";

    // Pre-create the account via the API so we can sign in cleanly.
    const res = await request.post(`${BASE_URL}/users`, {
      headers: { "Content-Type": "application/ld+json" },
      data: {
        email,
        plainPassword: password,
        givenName: "Redirect",
        familyName: "Tester",
      },
    });
    expect(res.ok()).toBeTruthy();

    // Visit a restricted page anonymously.
    await page.goto(`${BASE_URL}/projects`);

    // We're bounced to /signin and the original path is preserved on `next`.
    await expect(page).toHaveURL(/\/signin\?next=%2Fprojects/);

    // Submit the sign-in form.
    await page.fill("#email", email);
    await page.fill("#password", password);
    await page.click('button[type="submit"]');

    // Now we're back on the page we originally tried to visit.
    await expect(page).toHaveURL(/\/projects$/);
  });

  test("/signup tab on the unified auth card preserves ?next through registration", async ({
    page,
  }) => {
    const email = uniqueEmail("signup-redirect");
    const password = "password123";

    // Land on /signin?next=/tasks, then click into the Sign Up tab.
    // The Radix Tabs trigger has role="tab" while the form submit is a
    // normal <button>, so role-based selectors disambiguate them — both
    // happen to be labelled "Sign Up".
    await page.goto(`${BASE_URL}/signin?next=%2Ftasks`);
    await page.getByRole("tab", { name: "Sign Up" }).click();

    await page.fill('input[name="givenName"]', "New");
    await page.fill('input[name="familyName"]', "User");
    await page.fill('input[name="email"]', email);
    await page.fill('input[name="password"]', password);
    await page.fill('input[name="confirmPassword"]', password);
    await page.getByRole("button", { name: "Sign Up" }).click();

    // Registration redirects to /signin with both `registered=true` and the
    // preserved next. Sign in to land on /tasks.
    await expect(page).toHaveURL(/\/signin\?.*registered=true.*next=%2Ftasks/);
    await page.fill("#email", email);
    await page.fill("#password", password);
    await page.click('button[type="submit"]');
    await expect(page).toHaveURL(/\/tasks$/);
  });

  // Each entry is a `?next=` value that should be rejected by `safeNextPath()`
  // and fall back to /account. The list is the full set of attack vectors the
  // helper guards against; if any of them ever land the user somewhere else,
  // we want CI to scream.
  const HOSTILE_NEXT_VALUES = [
    {
      label: "protocol-relative URL (`//host/...`)",
      value: "//evil.example.com/x",
    },
    {
      label: "absolute http URL",
      value: "http://evil.example.com/x",
    },
    {
      label: "absolute https URL",
      value: "https://evil.example.com/x",
    },
    {
      label: "javascript: URI",
      value: "javascript:alert(1)",
    },
    {
      label: "data: URI",
      value: "data:text/html,<script>alert(1)</script>",
    },
    {
      label: "backslash-after-slash (browser normalises to //host)",
      value: "/\\evil.example.com/x",
    },
    {
      label: "relative path without leading slash",
      value: "projects",
    },
    {
      label: "empty string",
      value: "",
    },
  ];

  for (const { label, value } of HOSTILE_NEXT_VALUES) {
    test(`hostile ?next is ignored: ${label}`, async ({ page, request }) => {
      const email = uniqueEmail("safe-next");
      const password = "password123";
      await request.post(`${BASE_URL}/users`, {
        headers: { "Content-Type": "application/ld+json" },
        data: {
          email,
          plainPassword: password,
          givenName: "Safe",
          familyName: "Tester",
        },
      });

      await page.goto(`${BASE_URL}/signin?next=${encodeURIComponent(value)}`);
      await page.fill("#email", email);
      await page.fill("#password", password);
      await page.click('button[type="submit"]');

      // Falls back to the default landing page rather than wherever
      // the attacker tried to send us.
      await expect(page).toHaveURL(/\/account$/);
    });
  }
});
