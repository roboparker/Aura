// @ts-check
const { test, expect } = require("@playwright/test");
const {
  BASE_URL,
  uniqueEmail: shared,
  registerAndSignIn,
  fillDescription,
} = require("./helpers");

const uniqueEmail = () => shared("discussions");

test.describe("Discussions", () => {
  test("project owner can post, filter, edit, pin, and delete a discussion", async ({
    page,
  }) => {
    await registerAndSignIn(page, uniqueEmail());

    // Create a project to host the discussion.
    await page.goto(`${BASE_URL}/projects`);
    const projectTitle = `Discuss-host-${Date.now()}`;
    await page.fill("#title", projectTitle);
    await page.click('button[type="submit"]');
    const projectItem = page.locator('[data-testid="project-item"]', {
      hasText: projectTitle,
    });
    await expect(projectItem).toBeVisible();

    // Open the project detail page and follow the Discussions link to the
    // dedicated list page.
    await projectItem.locator(`a[href^="/projects/"]`).first().click();
    await expect(page).toHaveURL(/\/projects\/[a-f0-9-]+/);
    await page.click('[data-testid="project-discussions-link"]');
    await expect(page).toHaveURL(/\/projects\/[a-f0-9-]+\/discussions$/);

    // Empty state shows the right copy.
    const panel = page.locator('[data-testid="discussions-panel"]');
    await expect(panel).toBeVisible();
    await expect(
      panel.locator("text=No discussions yet"),
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
    await expect(discussion.locator("text=Ideas")).toBeVisible();

    // Filter by category — switching to "Q&A" hides the new "ideas" post.
    await page.getByRole("tab", { name: "Q&A" }).click();
    await expect(discussion).toHaveCount(0);
    await page.getByRole("tab", { name: "Ideas" }).click();
    await expect(discussion).toBeVisible();

    // Owner pin / unpin from the list.
    await discussion.locator('[data-testid="discussion-toggle-pin"]').click();
    await expect(discussion.locator("text=Pinned")).toBeVisible();
    await discussion.locator('[data-testid="discussion-toggle-pin"]').click();
    await expect(discussion.locator("text=Pinned")).toHaveCount(0);

    // Drill into the detail page via the row's title link.
    await discussion.locator('[data-testid="discussion-title"]').click();
    await expect(page).toHaveURL(
      /\/projects\/[a-f0-9-]+\/discussions\/[a-f0-9-]+$/,
    );
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
    await expect(page).toHaveURL(/\/projects\/[a-f0-9-]+\/discussions$/);
    await expect(
      page.locator('[data-testid="discussion-item"]', {
        hasText: "Switch to pnpm",
      }),
    ).toHaveCount(0);
  });

});

// Cross-user project visibility is already covered by `projects.spec.js`;
// the panel inherits it via `ProjectAccessExtension` and `DiscussionAccessExtension`,
// both of which have direct PHPUnit coverage in `DiscussionTest`.
