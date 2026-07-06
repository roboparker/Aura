// @ts-check
const { test, expect } = require("@playwright/test");
const {
  BASE_URL,
  uniqueEmail: shared,
  registerAndSignIn,
  createTaskInline,
  fillDescription,
} = require("./helpers");

const uniqueEmail = () => shared("comments");

test.describe("Task comments", () => {
  test("user can post, edit, and delete a comment on their own task", async ({
    page,
  }) => {
    await registerAndSignIn(page, uniqueEmail());
    // Auto-accept the delete confirmation later in the test.
    page.on("dialog", (dialog) => dialog.accept());

    await page.goto(`${BASE_URL}/tasks`);
    const title = `Plan launch ${Date.now()}`;
    await createTaskInline(page, title);

    const item = page.locator('[data-testid="task-item"]', { hasText: title });
    await expect(item).toBeVisible();

    // Toggle the comments panel for this task.
    await item.locator('[data-testid="task-comments-toggle"]').click();
    const panel = item.locator('[data-testid="comments-panel"]');
    await expect(panel).toBeVisible();
    await expect(panel.locator("text=No comments yet")).toBeVisible();

    // Post a comment by typing into the BlockNote editor and clicking Post.
    await fillDescription(page, panel, "First note about the launch.", {
      ariaLabelPrefix: "Comment",
    });
    const submit = panel.locator('[data-testid="comment-submit"]');
    await submit.scrollIntoViewIfNeeded();
    await submit.click();

    const comment = panel.locator('[data-testid="comment-item"]').first();
    await expect(comment).toContainText("First note about the launch.");

    // Toggle count is reflected on the toggle pill now.
    await expect(
      item.locator('[data-testid="task-comments-toggle"]'),
    ).toContainText("Comments (1)");

    // Edit the comment.
    await comment.locator('[data-testid="comment-edit"]').click();
    await fillDescription(page, comment, "Edited launch note.", {
      ariaLabelPrefix: "Edit comment",
    });
    await comment.locator('[data-testid="comment-edit-save"]').click();
    await expect(comment).toContainText("Edited launch note.");
    await expect(comment).toContainText("edited");

    // Delete it.
    await comment.locator('[data-testid="comment-delete"]').click();
    await expect(panel.locator('[data-testid="comment-item"]')).toHaveCount(0);
    await expect(
      item.locator('[data-testid="task-comments-toggle"]'),
    ).toContainText("Comment");
  });

  test("comments survive a reload", async ({ page }) => {
    await registerAndSignIn(page, uniqueEmail());
    await page.goto(`${BASE_URL}/tasks`);
    const title = `Persist note ${Date.now()}`;
    await createTaskInline(page, title);

    const item = page.locator('[data-testid="task-item"]', { hasText: title });
    await item.locator('[data-testid="task-comments-toggle"]').click();
    const panel = item.locator('[data-testid="comments-panel"]');
    await fillDescription(page, panel, "Survives reload.", {
      ariaLabelPrefix: "Comment",
    });
    await panel.locator('[data-testid="comment-submit"]').click();
    await expect(
      panel.locator('[data-testid="comment-item"]'),
    ).toContainText("Survives reload.");

    await page.reload();
    const reopenedItem = page.locator('[data-testid="task-item"]', {
      hasText: title,
    });
    await reopenedItem.locator('[data-testid="task-comments-toggle"]').click();
    await expect(
      reopenedItem
        .locator('[data-testid="comments-panel"]')
        .locator('[data-testid="comment-item"]'),
    ).toContainText("Survives reload.");
  });

  test("non-author cannot see edit affordance", async ({ browser }) => {
    // Alice owns a board task; Bob is a member who comments. Alice opens
    // the panel and should see her own delete option (task owner) but no
    // edit pencil on Bob's comment.
    const aliceEmail = uniqueEmail();
    const bobEmail = uniqueEmail();

    const aliceCtx = await browser.newContext({ ignoreHTTPSErrors: true });
    const alice = await aliceCtx.newPage();
    await registerAndSignIn(alice, aliceEmail);
    // Alice creates a board + task via API to isolate from inline UI.
    const boardRes = await alice.request.post(`${BASE_URL}/boards`, {
      headers: { "Content-Type": "application/ld+json" },
      data: { title: `Shared ${Date.now()}` },
    });
    expect(boardRes.ok()).toBeTruthy();
    const board = await boardRes.json();

    // Bob signs up — needs to exist in the DB before Alice can add him by email.
    const bobCtx = await browser.newContext({ ignoreHTTPSErrors: true });
    const bob = await bobCtx.newPage();
    await registerAndSignIn(bob, bobEmail);

    // Alice adds Bob to the board (the endpoint takes email, not user id).
    const addRes = await alice.request.post(
      `${BASE_URL}${board["@id"]}/members`,
      {
        headers: { "Content-Type": "application/json" },
        data: { email: bobEmail },
      },
    );
    expect(addRes.ok()).toBeTruthy();

    // Alice creates a task on the board.
    const taskRes = await alice.request.post(`${BASE_URL}/tasks`, {
      headers: { "Content-Type": "application/ld+json" },
      data: { title: `Team task ${Date.now()}`, board: board["@id"] },
    });
    expect(taskRes.ok()).toBeTruthy();
    const task = await taskRes.json();

    // Bob posts a comment via API (#228 unified the resource).
    const commentRes = await bob.request.post(`${BASE_URL}/comments`, {
      headers: { "Content-Type": "application/ld+json" },
      data: { task: task["@id"], body: "Bob's note." },
    });
    expect(commentRes.ok()).toBeTruthy();

    // Alice loads the page and expands comments.
    await alice.goto(`${BASE_URL}/tasks`);
    const aliceItem = alice.locator('[data-testid="task-item"]', {
      hasText: task.title,
    });
    await aliceItem.locator('[data-testid="task-comments-toggle"]').click();
    const aliceComment = aliceItem.locator('[data-testid="comment-item"]');
    await expect(aliceComment).toContainText("Bob's note.");
    // No edit pencil — Alice is not the author.
    await expect(
      aliceComment.locator('[data-testid="comment-edit"]'),
    ).toHaveCount(0);
    // But Alice IS the task owner, so she sees the delete option.
    await expect(
      aliceComment.locator('[data-testid="comment-delete"]'),
    ).toBeVisible();

    await aliceCtx.close();
    await bobCtx.close();
  });
});
