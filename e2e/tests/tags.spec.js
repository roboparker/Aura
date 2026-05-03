// @ts-check
const { test, expect } = require("@playwright/test");
const {
  BASE_URL,
  uniqueEmail: shared,
  registerAndSignIn,
  fillDescription,
  createTaskInline,
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
    await createTaskInline(page, taskTitle);
    const item = page.locator('[data-testid="task-item"]', { hasText: taskTitle });

    // No tags attached yet
    await expect(item.locator('[data-testid="task-tag"]')).toHaveCount(0);

    // Open the tag picker (TagsCombobox edit button — `Add tags for "<title>"`
    // when empty, `Edit tags for "<title>"` once any are attached). Then
    // click into the chip-input so Base UI opens the options popover.
    await item.getByRole("button", { name: new RegExp(`tags for "${taskTitle}"`) }).click();
    const tagInput = item.locator('[data-slot="combobox-chip-input"]');
    await tagInput.click();
    await page.getByRole("option", { name: `Blue-${suffix}` }).click();
    await expect(item.locator('[data-testid="task-tag"]')).toContainText(`Blue-${suffix}`);

    // Picker may close after a pick — click the input again to reopen, then
    // pick Red.
    await tagInput.click();
    await page.getByRole("option", { name: `Red-${suffix}` }).click();
    await expect(item.locator('[data-testid="task-tag"]')).toHaveCount(2);
    // Click the page heading to take focus out of the combobox.
    await page.locator("h1", { hasText: "Tasks" }).click();

    // Reload — order persisted by server
    await page.reload();
    const reloaded = page.locator('[data-testid="task-item"]', { hasText: taskTitle });
    await expect(reloaded.locator('[data-testid="task-tag"]')).toHaveCount(2);

    // Remove Blue tag via the X on the chip (Base UI ChipRemove inside the
    // chip whose aria-label matches the tag title).
    await reloaded
      .locator(`[data-slot="combobox-chip"][aria-label="Blue-${suffix}"]`)
      .locator('[data-slot="combobox-chip-remove"]')
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
    await createTaskInline(page, taskTitle);
    const item = page.locator('[data-testid="task-item"]', { hasText: taskTitle });
    await item.getByRole("button", { name: new RegExp(`tags for "${taskTitle}"`) }).click();
    // Click into the chip-input to open the Base UI Combobox popover.
    await item.locator('[data-slot="combobox-chip-input"]').click();
    await page.getByRole("option", { name: tagTitle }).click();
    await expect(item.locator('[data-testid="task-tag"]')).toContainText(tagTitle);
    // Dismiss the popover.
    await page.locator("h1", { hasText: "Tasks" }).click();

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
