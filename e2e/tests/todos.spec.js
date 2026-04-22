// @ts-check
const { test, expect } = require("@playwright/test");

const BASE_URL = "https://localhost";

const uniqueEmail = () => `todos-${Date.now()}-${Math.random().toString(36).slice(2, 8)}@example.com`;

async function registerAndSignIn(page, email, password = "password123") {
  const res = await page.request.post(`${BASE_URL}/users`, {
    headers: { "Content-Type": "application/ld+json" },
    data: { email, plainPassword: password },
  });
  expect(res.ok()).toBeTruthy();

  await page.goto(`${BASE_URL}/signin`);
  await page.fill("#email", email);
  await page.fill("#password", password);
  await page.click('button[type="submit"]');
  await expect(page).toHaveURL(/\/account/);
}

test.describe("Todos", () => {
  test("unauthenticated visitors are redirected to sign in", async ({ page }) => {
    await page.goto(`${BASE_URL}/todos`);
    await expect(page).toHaveURL(/\/signin/);
  });

  test("user can create, complete, and delete a todo", async ({ page }) => {
    await registerAndSignIn(page, uniqueEmail());

    await page.goto(`${BASE_URL}/todos`);
    await expect(page).toHaveTitle("Todos - Aura");
    await expect(page.locator("text=No todos yet")).toBeVisible();

    // Create
    const title = `Buy groceries ${Date.now()}`;
    await page.fill("#title", title);
    await page.fill("#description", "Milk, eggs, bread");
    await page.click('button[type="submit"]');

    const item = page.locator('[data-testid="todo-item"]', { hasText: title });
    await expect(item).toBeVisible();
    await expect(item.locator("text=Milk, eggs, bread")).toBeVisible();

    // Form clears after submit
    await expect(page.locator("#title")).toHaveValue("");

    // Complete
    await item.locator('input[type="checkbox"]').check();
    await expect(item.locator(`text=${title}`)).toHaveClass(/line-through/);

    // Uncomplete
    await item.locator('input[type="checkbox"]').uncheck();
    await expect(item.locator(`text=${title}`)).not.toHaveClass(/line-through/);

    // Delete
    await item.getByRole("button", { name: /Delete/i }).click();
    await expect(item).toHaveCount(0);
  });

  test("users only see their own todos", async ({ browser }) => {
    const aliceEmail = uniqueEmail();
    const bobEmail = uniqueEmail();

    // Alice creates a todo
    const aliceContext = await browser.newContext({ ignoreHTTPSErrors: true });
    const alicePage = await aliceContext.newPage();
    await registerAndSignIn(alicePage, aliceEmail);
    await alicePage.goto(`${BASE_URL}/todos`);
    const aliceTitle = `Alice secret ${Date.now()}`;
    await alicePage.fill("#title", aliceTitle);
    await alicePage.click('button[type="submit"]');
    await expect(
      alicePage.locator('[data-testid="todo-item"]', { hasText: aliceTitle }),
    ).toBeVisible();

    // Bob signs in in an isolated context and should not see Alice's todo
    const bobContext = await browser.newContext({ ignoreHTTPSErrors: true });
    const bobPage = await bobContext.newPage();
    await registerAndSignIn(bobPage, bobEmail);
    await bobPage.goto(`${BASE_URL}/todos`);
    await expect(bobPage.locator(`text=${aliceTitle}`)).not.toBeVisible();
    await expect(bobPage.locator("text=No todos yet")).toBeVisible();

    await aliceContext.close();
    await bobContext.close();
  });

  test("nav shows Todos link when authenticated", async ({ page }) => {
    await registerAndSignIn(page, uniqueEmail());
    await expect(page.locator('nav >> text=Todos')).toBeVisible();
    await page.locator('nav >> text=Todos').click();
    await expect(page).toHaveURL(/\/todos/);
  });

  test("user can reorder todos via keyboard drag", async ({ page }) => {
    // dnd-kit's KeyboardSensor is deterministic across browsers, unlike
    // pointer-based drag which is flaky with dnd-kit's distance constraint.
    // Space picks up the grip, ArrowDown moves it, Space drops.
    await registerAndSignIn(page, uniqueEmail());
    await page.goto(`${BASE_URL}/todos`);

    // New todos go to the top, so creating in order A, B, C produces list C, B, A.
    // Keep suffixes unique in case the browser uses a cached network response.
    const suffix = Date.now();
    const titles = [`A-${suffix}`, `B-${suffix}`, `C-${suffix}`];
    for (const t of titles) {
      await page.fill("#title", t);
      await page.click('button[type="submit"]');
      await expect(
        page.locator('[data-testid="todo-item"]', { hasText: t }),
      ).toBeVisible();
    }

    // Verify initial order (newest first): C, B, A
    const listItems = page.locator('[data-testid="todo-item"] p.font-medium').first();
    await expect(listItems).toHaveText(titles[2]);

    // Grab the grip on the top item (C) and move it down twice to position 3
    const topGrip = page
      .locator('[data-testid="todo-item"]')
      .first()
      .getByRole("button", { name: /Drag to reorder/ });
    await topGrip.focus();
    await page.keyboard.press("Space");
    await page.keyboard.press("ArrowDown");
    await page.keyboard.press("ArrowDown");
    await page.keyboard.press("Space");

    // Now the order should be B, A, C — verify via the top item
    await expect(
      page.locator('[data-testid="todo-item"]').first().locator("p.font-medium"),
    ).toHaveText(titles[1]);

    // Reloading should preserve the server-persisted order
    await page.reload();
    await expect(
      page.locator('[data-testid="todo-item"]').first().locator("p.font-medium"),
    ).toHaveText(titles[1]);
    await expect(
      page.locator('[data-testid="todo-item"]').last().locator("p.font-medium"),
    ).toHaveText(titles[2]);
  });
});
