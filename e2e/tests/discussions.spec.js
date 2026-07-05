// @ts-check
const { test, expect } = require("@playwright/test");
const {
  BASE_URL,
  uniqueEmail: shared,
  registerAndSignIn,
  fillDescription,
} = require("./helpers");

const uniqueEmail = () => shared("discussions");

/**
 * Open a discussion row's "…" overflow menu and click one of its items.
 *
 * Pinning re-sorts the row from the "All discussions" section into the
 * "Pinned" section, which unmounts and remounts the row. A click on the
 * freshly-remounted trigger can race the remount and fail to open the
 * portal menu, so retry the open until the target item is actually
 * visible before clicking it.
 */
async function clickRowMenuItem(page, row, testid) {
  const item = page.locator(`[data-testid="${testid}"]`);
  await expect(async () => {
    await row.locator('[data-testid="discussion-row-menu"]').click();
    await expect(item).toBeVisible({ timeout: 2000 });
  }).toPass();
  await item.click();
}

test.describe("Discussions", () => {
  test("space member can post, filter, edit, pin, and delete a discussion", async ({
    page,
  }) => {
    await registerAndSignIn(page, uniqueEmail());

    // Go straight to the top-level /discussions page — scoped to the
    // user's active space (Private by default).
    await page.goto(`${BASE_URL}/discussions`);

    const panel = page.locator('[data-testid="discussions-panel"]');
    await expect(panel).toBeVisible();
    await expect(
      panel.locator("text=No discussions in this space yet"),
    ).toBeVisible();

    // Open the composer, post a discussion in "ideas".
    await page.click('[data-testid="discussion-toggle-composer"]');
    const composer = page.locator('[data-testid="discussion-composer"]');
    await expect(composer).toBeVisible();
    await composer.locator("#discussion-title").fill("Switch to pnpm");
    await composer.locator("#discussion-category").selectOption("ideas");
    await fillDescription(page, composer, "Faster installs for everyone.", {
      ariaLabelPrefix: "Discussion body",
    });
    await composer.locator('[data-testid="discussion-submit"]').click();

    const discussion = page.locator('[data-testid="discussion-item"]', {
      hasText: "Switch to pnpm",
    });
    await expect(discussion).toBeVisible();
    // The row renders the category badge twice (desktop column + mobile
    // meta line); assert against whichever is visible at this viewport.
    await expect(
      discussion.locator('[data-testid="discussion-category-badge"]:visible'),
    ).toContainText("Ideas");

    // Filter by category — switching to "Q&A" hides the new "ideas" post.
    await page.getByRole("tab", { name: "Q&A" }).click();
    await expect(discussion).toHaveCount(0);
    await page.getByRole("tab", { name: "Ideas" }).click();
    await expect(discussion).toBeVisible();

    // Admin pin / unpin from the list — actions live in the row's
    // "…" overflow menu (rendered in a portal once opened).
    await clickRowMenuItem(page, discussion, "discussion-toggle-pin");
    await expect(discussion.locator('[aria-label="Pinned"]')).toBeVisible();
    await clickRowMenuItem(page, discussion, "discussion-toggle-pin");
    await expect(discussion.locator('[aria-label="Pinned"]')).toHaveCount(0);

    // Drill into the detail page via the row's title link.
    await discussion.locator('[data-testid="discussion-title"]').click();
    await expect(page).toHaveURL(/\/discussions\/[a-f0-9-]+$/);
    const detail = page.locator('[data-testid="discussion-detail"]');
    await expect(detail).toBeVisible();
    await expect(
      detail.locator("text=Faster installs for everyone."),
    ).toBeVisible();

    // Author edits from the detail page.
    await detail.locator('[data-testid="discussion-edit"]').click();
    await detail
      .locator(`#edit-title`)
      .fill("Switch to pnpm (revised)");
    await fillDescription(page, detail, "Even faster after benchmarks.", {
      ariaLabelPrefix: "Edit discussion body",
    });
    await detail.locator('[data-testid="discussion-save-edit"]').click();
    await expect(
      detail.locator('[data-testid="discussion-detail-title"]', {
        hasText: "Switch to pnpm (revised)",
      }),
    ).toBeVisible();

    // Delete from the detail page — accept the confirm dialog. After delete
    // we redirect back to the list, where the row should be gone.
    page.once("dialog", (dialog) => dialog.accept());
    await detail.locator('[data-testid="discussion-delete"]').click();
    await expect(page).toHaveURL(/\/discussions$/);
    await expect(
      page.locator('[data-testid="discussion-item"]', {
        hasText: "Switch to pnpm",
      }),
    ).toHaveCount(0);
  });
});

// Cross-user space visibility is already covered by `boards.spec.js`;
// the panel inherits it via `SpaceAccessExtension` and `DiscussionAccessExtension`,
// both of which have direct PHPUnit coverage in `DiscussionTest`.
