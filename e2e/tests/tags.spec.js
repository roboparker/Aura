// @ts-check
const { test, expect } = require("@playwright/test");
const {
  BASE_URL,
  uniqueEmail: shared,
  registerAndSignIn,
  fillDescription,
  openAccountMenu,
} = require("./helpers");

const uniqueEmail = () => shared("tags");

test.describe("Tags", () => {
  test("unauthenticated visitors are redirected to sign in", async ({ page }) => {
    await page.goto(`${BASE_URL}/tags`);
    await expect(page).toHaveURL(/\/signin/);
  });

  test("user can create, edit, and delete a tag", async ({ page }) => {
    await registerAndSignIn(page, uniqueEmail());

    await page.goto(`${BASE_URL}/tags`);
    await expect(page).toHaveTitle("Tags - Aura");
    await expect(page.locator("text=No tags yet")).toBeVisible();

    const title = `Urgent-${Date.now()}`;
    await page.fill("#title", title);
    await fillDescription(page, undefined, "High priority");
    await page.click('button[type="submit"]');

    const item = page.locator('[data-testid="tag-item"]', { hasText: title });
    await expect(item).toBeVisible();
    await expect(item.locator("text=High priority")).toBeVisible();

    // Edit — title update. Once the edit form opens, the li's text content is
    // replaced by form inputs (whose `value` isn't matched by `hasText`), so
    // re-scope to the sole tag-item on the page instead of filtering by title.
    await item.getByRole("button", { name: /Edit/i }).click();
    const editingItem = page.locator('[data-testid="tag-item"]').first();
    await editingItem.getByLabel("Title").fill(`${title}-edited`);
    await editingItem.getByRole("button", { name: /Save/i }).click();
    await expect(
      page.locator('[data-testid="tag-item"]', { hasText: `${title}-edited` }),
    ).toBeVisible();

    // Delete — accept the confirm dialog
    page.once("dialog", (dialog) => dialog.accept());
    await page
      .locator('[data-testid="tag-item"]', { hasText: `${title}-edited` })
      .getByRole("button", { name: /Delete "/ })
      .click();
    await expect(page.locator("text=No tags yet")).toBeVisible();
  });

  test("user can add and remove tags on a task", async ({ page }) => {
    await registerAndSignIn(page, uniqueEmail());

    // Seed two tags
    const suffix = Date.now();
    await page.goto(`${BASE_URL}/tags`);
    for (const title of [`Blue-${suffix}`, `Red-${suffix}`]) {
      await page.fill("#title", title);
      await page.click('button[type="submit"]');
      await expect(
        page.locator('[data-testid="tag-item"]', { hasText: title }),
      ).toBeVisible();
    }

    // Create a task
    await page.goto(`${BASE_URL}/tasks`);
    const taskTitle = `Tagged task ${suffix}`;
    await page.fill("#title", taskTitle);
    await page.click('button[type="submit"]');
    const item = page.locator('[data-testid="task-item"]', { hasText: taskTitle });
    await expect(item).toBeVisible();

    // No tags attached yet
    await expect(item.locator('[data-testid="task-tag"]')).toHaveCount(0);

    // Attach Blue tag via the picker
    await item.getByRole("button", { name: /Add tag to/ }).click();
    await page.getByRole("menuitem", { name: `Blue-${suffix}` }).click();
    await expect(item.locator('[data-testid="task-tag"]')).toContainText(`Blue-${suffix}`);

    // Attach Red tag
    await item.getByRole("button", { name: /Add tag to/ }).click();
    await page.getByRole("menuitem", { name: `Red-${suffix}` }).click();
    await expect(item.locator('[data-testid="task-tag"]')).toHaveCount(2);

    // Reload — order persisted by server
    await page.reload();
    const reloaded = page.locator('[data-testid="task-item"]', { hasText: taskTitle });
    await expect(reloaded.locator('[data-testid="task-tag"]')).toHaveCount(2);

    // Remove Blue tag via the × on the badge
    await reloaded
      .getByRole("button", { name: `Remove tag "Blue-${suffix}"` })
      .click();
    await expect(reloaded.locator('[data-testid="task-tag"]')).toHaveCount(1);
    await expect(reloaded.locator('[data-testid="task-tag"]')).toContainText(`Red-${suffix}`);
  });

  test("deleting a tag removes its badges from tasks", async ({ page }) => {
    await registerAndSignIn(page, uniqueEmail());

    const suffix = Date.now();
    const tagTitle = `Transient-${suffix}`;
    const taskTitle = `Task ${suffix}`;

    // Create tag and task, then attach
    await page.goto(`${BASE_URL}/tags`);
    await page.fill("#title", tagTitle);
    await page.click('button[type="submit"]');
    await expect(page.locator('[data-testid="tag-item"]', { hasText: tagTitle })).toBeVisible();

    await page.goto(`${BASE_URL}/tasks`);
    await page.fill("#title", taskTitle);
    await page.click('button[type="submit"]');
    const item = page.locator('[data-testid="task-item"]', { hasText: taskTitle });
    await item.getByRole("button", { name: /Add tag to/ }).click();
    await page.getByRole("menuitem", { name: tagTitle }).click();
    await expect(item.locator('[data-testid="task-tag"]')).toContainText(tagTitle);

    // Delete the tag from the Tags page
    await page.goto(`${BASE_URL}/tags`);
    page.once("dialog", (dialog) => dialog.accept());
    await page
      .locator('[data-testid="tag-item"]', { hasText: tagTitle })
      .getByRole("button", { name: /Delete "/ })
      .click();
    await expect(page.locator("text=No tags yet")).toBeVisible();

    // Badge is gone from the task
    await page.goto(`${BASE_URL}/tasks`);
    const reloaded = page.locator('[data-testid="task-item"]', { hasText: taskTitle });
    await expect(reloaded.locator('[data-testid="task-tag"]')).toHaveCount(0);
  });

  test("users only see their own tags", async ({ browser }) => {
    const aliceEmail = uniqueEmail();
    const bobEmail = uniqueEmail();
    const suffix = Date.now();
    const aliceTag = `Alice-secret-${suffix}`;

    const aliceContext = await browser.newContext({ ignoreHTTPSErrors: true });
    const alicePage = await aliceContext.newPage();
    await registerAndSignIn(alicePage, aliceEmail);
    await alicePage.goto(`${BASE_URL}/tags`);
    await alicePage.fill("#title", aliceTag);
    await alicePage.click('button[type="submit"]');
    await expect(
      alicePage.locator('[data-testid="tag-item"]', { hasText: aliceTag }),
    ).toBeVisible();

    const bobContext = await browser.newContext({ ignoreHTTPSErrors: true });
    const bobPage = await bobContext.newPage();
    await registerAndSignIn(bobPage, bobEmail);
    await bobPage.goto(`${BASE_URL}/tags`);
    await expect(bobPage.locator(`text=${aliceTag}`)).not.toBeVisible();
    await expect(bobPage.locator("text=No tags yet")).toBeVisible();

    await aliceContext.close();
    await bobContext.close();
  });

  test("account menu shows Tags link when authenticated", async ({ page }) => {
    await registerAndSignIn(page, uniqueEmail());
    await openAccountMenu(page);
    await expect(page.locator("nav >> text=Tags")).toBeVisible();
    await page.locator("nav >> text=Tags").click();
    await expect(page).toHaveURL(/\/tags/);
  });
});
