// @ts-check
const { test, expect } = require("@playwright/test");
const { BASE_URL, uniqueEmail } = require("./helpers");

// Stand-in for a route-param value on detail pages. Pages mount and
// fire their auth check before any data fetch resolves, so the
// redirect happens whether or not the ID is real.
const PLACEHOLDER_UUID = "00000000-0000-0000-0000-000000000000";

// Every authed route the PWA exposes (#201). If a new authed page is
// added without a redirect guard the parameterised test below will
// fail on it. Pages with route params use PLACEHOLDER_UUID; the auth
// check runs before any fetch, so a non-existent ID is fine.
const AUTHED_PATHS = [
  "/account",
  "/settings",
  "/admin",
  "/my-tasks",
  "/tasks",
  "/projects",
  `/projects/${PLACEHOLDER_UUID}`,
  `/projects/${PLACEHOLDER_UUID}/custom-fields`,
  "/discussions",
  `/discussions/${PLACEHOLDER_UUID}`,
  "/pages",
  `/pages/${PLACEHOLDER_UUID}`,
  "/spaces",
  `/spaces/${PLACEHOLDER_UUID}`,
  "/groups",
  `/groups/${PLACEHOLDER_UUID}`,
  "/tags",
  "/search",
];

test.describe("Auth redirect", () => {
  for (const path of AUTHED_PATHS) {
    test(`unauthenticated visit to ${path} redirects to /signin`, async ({
      page,
    }) => {
      await page.goto(`${BASE_URL}${path}`);
      // Lands on /signin and preserves the original path on ?next so a
      // subsequent sign-in can bounce the user back. The encoded value
      // can differ slightly (slashes, query, etc.), so we just check
      // for the path fragment inside the encoded next param.
      await expect(page).toHaveURL(/\/signin/);
      const url = new URL(page.url());
      const next = url.searchParams.get("next");
      expect(next, `?next missing for ${path}`).toBeTruthy();
      expect(next, `?next mismatched for ${path}`).toBe(path);
    });
  }

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

  test("/signup link on the auth card preserves ?next through registration", async ({
    page,
  }) => {
    const email = uniqueEmail("signup-redirect");
    const password = "password123";

    // Land on /signin?next=/tasks, then follow the footer "Create an
    // account" link. The auth card no longer renders tabs — switching
    // forms is a plain <Link> in the card footer that navigates to
    // /signup while preserving the query string.
    await page.goto(`${BASE_URL}/signin?next=%2Ftasks`);
    await page.getByRole("link", { name: "Create an account" }).click();
    await expect(page).toHaveURL(/\/signup\?.*next=%2Ftasks/);

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
