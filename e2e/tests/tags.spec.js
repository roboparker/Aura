// @ts-check
const { test, expect } = require("@playwright/test");
const {
  BASE_URL,
  uniqueEmail: shared,
  registerAndSignIn,
  fillDescription,
  createTaskInline,
} = require("./helpers");

const uniqueEmail = () => shared("tags");

// Tags are space-scoped now (#tags). The /tags page opens an inline create
// row from the header "Add tag" button, and delete goes through the styled
// ConfirmDialog (not window.confirm).
async function createTag(page, title, description) {
  await page.getByRole("button", { name: "Add tag" }).first().click();
  const row = page.getByTestId("tag-create-row");
  await row.getByLabel("Title").fill(title);
  if (description) {
    await fillDescription(page, undefined, description);
  }
  await row.getByRole("button", { name: "Add tag" }).click();
  await expect(
    page.locator('[data-testid="tag-item"]', { hasText: title }),
  ).toBeVisible();
}

async function deleteTag(page, title) {
  await page
    .locator('[data-testid="tag-item"]', { hasText: title })
    .getByRole("button", { name: `Delete "${title}"` })
    .click();
  await page.getByRole("dialog").getByRole("button", { name: "Delete tag" }).click();
}

test.describe("Tags", () => {
  test("unauthenticated visitors are redirected to sign in", async ({ page }) => {
    await page.goto(`${BASE_URL}/tags`);
    await expect(page).toHaveURL(/\/signin/);
  });

  test("user can create, edit, and delete a tag", async ({ page }) => {
    await registerAndSignIn(page, uniqueEmail());

    await page.goto(`${BASE_URL}/tags`);
    await expect(page).toHaveTitle("Tags — Madori");
    await expect(page.locator("text=No tags yet")).toBeVisible();

    const title = `Urgent-${Date.now()}`;
    await createTag(page, title, "High priority");

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

    // Delete — confirm in the styled dialog.
    await deleteTag(page, `${title}-edited`);
    await expect(page.locator("text=No tags yet")).toBeVisible();
  });

  test("user can add and remove tags on a task", async ({ page }) => {
    await registerAndSignIn(page, uniqueEmail());

    // Seed two tags
    const suffix = Date.now();
    await page.goto(`${BASE_URL}/tags`);
    for (const title of [`Blue-${suffix}`, `Red-${suffix}`]) {
      await createTag(page, title);
    }

    // Create a task
    await page.goto(`${BASE_URL}/tasks`);
    const taskTitle = `Tagged task ${suffix}`;
    await createTaskInline(page, taskTitle);
    const item = page.locator('[data-testid="task-item"]', { hasText: taskTitle });

    // No tags attached yet
    await expect(item.locator('[data-testid="task-tag"]')).toHaveCount(0);

    // The tags combobox input is always present; click into it so Base UI
    // opens the options popover.
    const tagInput = item.locator('[data-testid="task-tags"] [data-slot="combobox-chip-input"]');
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
    await createTag(page, tagTitle);

    await page.goto(`${BASE_URL}/tasks`);
    await createTaskInline(page, taskTitle);
    const item = page.locator('[data-testid="task-item"]', { hasText: taskTitle });
    // Click into the always-present chip-input to open the Base UI Combobox popover.
    await item.locator('[data-testid="task-tags"] [data-slot="combobox-chip-input"]').click();
    await page.getByRole("option", { name: tagTitle }).click();
    await expect(item.locator('[data-testid="task-tag"]')).toContainText(tagTitle);
    // Dismiss the popover.
    await page.locator("h1", { hasText: "Tasks" }).click();

    // Delete the tag from the Tags page
    await page.goto(`${BASE_URL}/tags`);
    await deleteTag(page, tagTitle);
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
    await createTag(alicePage, aliceTag);

    const bobContext = await browser.newContext({ ignoreHTTPSErrors: true });
    const bobPage = await bobContext.newPage();
    await registerAndSignIn(bobPage, bobEmail);
    await bobPage.goto(`${BASE_URL}/tags`);
    await expect(bobPage.locator(`text=${aliceTag}`)).not.toBeVisible();
    await expect(bobPage.locator("text=No tags yet")).toBeVisible();

    await aliceContext.close();
    await bobContext.close();
  });

  // Removed "account menu shows Tags link" — Tags is no longer a
  // top-level sidebar link after the sidebar redesign
  // (#nav-refresh). The /tags page is still reachable directly and
  // covered by the other tests in this file.
});
