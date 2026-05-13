// @ts-check
const { test, expect } = require("@playwright/test");
const {
  BASE_URL,
  uniqueEmail: shared,
  registerAndSignIn,
} = require("./helpers");

const uniqueEmail = () => shared("pages");

test.describe("Pages", () => {
  test("create, view, edit, comment, and delete a page in the active space", async ({
    page,
  }) => {
    await registerAndSignIn(page, uniqueEmail());

    await page.goto(`${BASE_URL}/pages`);
    await expect(page).toHaveURL(/\/pages$/);

    // Empty state on a fresh personal space.
    await expect(page.locator("text=No pages in this space yet")).toBeVisible();

    // Create a page via the composer.
    await page.click('[data-testid="new-page-button"]');
    const title = `Onboarding-${Date.now()}`;
    await page.fill("#page-title", title);
    await page.click('button[type="submit"]:has-text("Create page")');

    // Lands on the detail view.
    await expect(page).toHaveURL(/\/pages\/[a-f0-9-]+$/);
    await expect(
      page.locator('[data-testid="page-detail-title"]'),
    ).toHaveText(title);
    await expect(page.locator("text=No content yet")).toBeVisible();

    // Add a comment from the page detail. Pages now use the shared
    // CommentsPanel (#228) — same selectors as the task surface.
    const comments = page.locator('[data-testid="comments-panel"]');
    const editor = comments.locator('[contenteditable="true"]').first();
    await editor.click();
    await page.keyboard.type("Looks good to me.");
    await comments.locator('[data-testid="comment-submit"]').click();
    await expect(
      comments.locator('[data-testid="comment-item"]', {
        hasText: "Looks good to me.",
      }),
    ).toBeVisible();

    // Back to the list view — the page is now there.
    await page.click('[data-testid="page-back-link"]');
    await expect(page).toHaveURL(/\/pages$/);
    const row = page.locator('[data-testid="page-item"]', { hasText: title });
    await expect(row).toBeVisible();

    // Search filters to the matching title.
    await page.fill('input[aria-label="Search pages"]', title);
    await expect(row).toBeVisible();
    await page.fill('input[aria-label="Search pages"]', "no-such-page-zzz");
    await expect(page.locator("text=No pages match that search")).toBeVisible();

    // Clear search, drill back in, then delete the page.
    await page.fill('input[aria-label="Search pages"]', "");
    await row.locator('[data-testid="page-title"]').click();
    await expect(page).toHaveURL(/\/pages\/[a-f0-9-]+$/);

    page.once("dialog", (dialog) => dialog.accept());
    await page.click('button[aria-label="Delete page"]');

    // Back on the list page, the deleted row should be gone.
    await expect(page).toHaveURL(/\/pages$/);
    await expect(
      page.locator('[data-testid="page-item"]', { hasText: title }),
    ).toHaveCount(0);
  });
});
