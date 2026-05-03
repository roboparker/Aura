// @ts-check
const { test, expect } = require("@playwright/test");
const {
  BASE_URL,
  uniqueEmail: shared,
  registerAndSignIn,
  createTaskInline,
  openAccountMenu,
} = require("./helpers");

const uniqueEmail = () => shared("tasks");

test.describe("Tasks", () => {
  test("unauthenticated visitors are redirected to sign in", async ({ page }) => {
    await page.goto(`${BASE_URL}/tasks`);
    await expect(page).toHaveURL(/\/signin/);
  });

  test("user can create, complete, and delete a task", async ({ page }) => {
    await registerAndSignIn(page, uniqueEmail());

    await page.goto(`${BASE_URL}/tasks`);
    await expect(page).toHaveTitle("Tasks - Aura");
    // Empty state: only the new-task input row, no real task rows.
    await expect(page.locator('[data-testid="task-item"]')).toHaveCount(0);

    // Create — title + staged description via the inline new-task row.
    const title = `Buy groceries ${Date.now()}`;
    await createTaskInline(page, title, { description: "Milk, eggs, bread" });

    const item = page.locator('[data-testid="task-item"]', { hasText: title });
    await expect(item.locator("text=Milk, eggs, bread")).toBeVisible();

    // New-task input clears after submit and is ready for the next entry.
    await expect(
      page.locator('[data-testid="new-task-title-input"]'),
    ).toHaveValue("");

    // Complete
    await item.locator('input[type="checkbox"]').check();
    await expect(item.locator(`text=${title}`)).toHaveClass(/line-through/);

    // Uncomplete
    await item.locator('input[type="checkbox"]').uncheck();
    await expect(item.locator(`text=${title}`)).not.toHaveClass(/line-through/);

    // Delete (trash icon button — accessible name is `Delete "<title>"`)
    await item.getByRole("button", { name: /^Delete "/ }).click();
    await expect(item).toHaveCount(0);
  });

  test("users only see their own tasks", async ({ browser }) => {
    const aliceEmail = uniqueEmail();
    const bobEmail = uniqueEmail();

    // Alice creates a task
    const aliceContext = await browser.newContext({ ignoreHTTPSErrors: true });
    const alicePage = await aliceContext.newPage();
    await registerAndSignIn(alicePage, aliceEmail);
    await alicePage.goto(`${BASE_URL}/tasks`);
    const aliceTitle = `Alice secret ${Date.now()}`;
    await createTaskInline(alicePage, aliceTitle);

    // Bob signs in in an isolated context and should not see Alice's task
    const bobContext = await browser.newContext({ ignoreHTTPSErrors: true });
    const bobPage = await bobContext.newPage();
    await registerAndSignIn(bobPage, bobEmail);
    await bobPage.goto(`${BASE_URL}/tasks`);
    await expect(bobPage.locator(`text=${aliceTitle}`)).not.toBeVisible();
    await expect(bobPage.locator('[data-testid="task-item"]')).toHaveCount(0);

    await aliceContext.close();
    await bobContext.close();
  });

  test("account menu shows Tasks link when authenticated", async ({ page }) => {
    await registerAndSignIn(page, uniqueEmail());
    await openAccountMenu(page);
    // `text=Tasks` would substring-match the new "My Tasks" entry too —
    // pin to an exact-name role lookup so each link is targeted on its own.
    const tasksLink = page.locator("nav").getByRole("link", {
      name: "Tasks",
      exact: true,
    });
    await expect(tasksLink).toBeVisible();
    await tasksLink.click();
    await expect(page).toHaveURL(/\/tasks$/);
  });

  test("user can reorder tasks via keyboard drag", async ({ page }) => {
    // dnd-kit's KeyboardSensor is deterministic across browsers, unlike
    // pointer-based drag which is flaky with dnd-kit's distance constraint.
    // Space picks up the grip, ArrowDown moves it, Space drops.
    await registerAndSignIn(page, uniqueEmail());
    await page.goto(`${BASE_URL}/tasks`);

    // New tasks go to the top, so creating in order A, B, C produces list C, B, A.
    // Keep suffixes unique in case the browser uses a cached network response.
    const suffix = Date.now();
    const titles = [`A-${suffix}`, `B-${suffix}`, `C-${suffix}`];
    for (const t of titles) {
      await createTaskInline(page, t);
    }

    // Verify initial order (newest first): C, B, A
    const listItems = page
      .locator('[data-testid="task-item"] [data-testid="task-title"]')
      .first();
    await expect(listItems).toHaveText(titles[2]);

    // Grab the grip on the top item (C) and move it down twice to position 3.
    // Use locator.press() to focus+press atomically (more reliable in CI than
    // focus() + keyboard.press), and wait briefly between keys so dnd-kit's
    // drag state has time to update between Space/Arrow events.
    const topGrip = page
      .locator('[data-testid="task-item"]')
      .first()
      .getByRole("button", { name: /Drag to reorder/ });
    await topGrip.press("Space");
    await page.waitForTimeout(100);
    await page.keyboard.press("ArrowDown");
    await page.waitForTimeout(100);
    await page.keyboard.press("ArrowDown");
    await page.waitForTimeout(100);
    await page.keyboard.press("Space");

    // Now the order should be B, A, C — verify via the top item
    await expect(
      page
        .locator('[data-testid="task-item"]')
        .first()
        .locator('[data-testid="task-title"]'),
    ).toHaveText(titles[1]);

    // Reloading should preserve the server-persisted order
    await page.reload();
    await expect(
      page
        .locator('[data-testid="task-item"]')
        .first()
        .locator('[data-testid="task-title"]'),
    ).toHaveText(titles[1]);
    await expect(
      page
        .locator('[data-testid="task-item"]')
        .last()
        .locator('[data-testid="task-title"]'),
    ).toHaveText(titles[2]);
  });
});
