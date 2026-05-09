// @ts-check
const { test, expect } = require("@playwright/test");
const {
  BASE_URL,
  uniqueEmail: shared,
  registerAndSignIn,
} = require("./helpers");

const uniqueEmail = () => shared("mentions");

test.describe("Comment @-mentions", () => {
  // Diagnostic spec for #153 — the user reported that comment mentions
  // "are not functioning" without specifying the surface, so this walks
  // the full pipe end-to-end: type `@<email-prefix>` into the comment
  // composer in BlockNote, post the comment, then verify (a) the body
  // that round-trips back through the API still contains the literal
  // `@token`, and (b) the recipient sees an unread notification.
  test("typing @<prefix> in a task comment notifies the prefix's owner", async ({
    browser,
  }) => {
    const aliceEmail = uniqueEmail();
    const bobEmail = uniqueEmail();

    // Bob signs up first so the email/personal space exist when Alice
    // adds him to a project.
    const bobContext = await browser.newContext({ ignoreHTTPSErrors: true });
    const bobPage = await bobContext.newPage();
    await registerAndSignIn(bobPage, bobEmail);

    const aliceContext = await browser.newContext({ ignoreHTTPSErrors: true });
    const alicePage = await aliceContext.newPage();
    await registerAndSignIn(alicePage, aliceEmail);

    // Alice creates a project + invites Bob via the
    // POST /projects/{id}/members shim that adds him to the parent
    // space (#185).
    await alicePage.goto(`${BASE_URL}/projects`);
    const projectTitle = `Mentions-${Date.now()}`;
    await alicePage.fill("#title", projectTitle);
    await alicePage.click('button[type="submit"]');
    const projectItem = alicePage.locator('[data-testid="project-item"]', {
      hasText: projectTitle,
    });
    await expect(projectItem).toBeVisible();

    // Open the project detail page and add Bob to the space via the
    // shared "Manage members in space" link.
    await projectItem.locator(`a[href^="/projects/"]`).first().click();
    await expect(alicePage).toHaveURL(/\/projects\/[a-f0-9-]+/);
    const projectIdMatch = alicePage.url().match(/\/projects\/([a-f0-9-]+)/);
    if (!projectIdMatch) throw new Error("Couldn't extract project ID from URL.");
    const projectId = projectIdMatch[1];
    await alicePage.request.post(
      `${BASE_URL}/projects/${projectId}/members`,
      { data: { email: bobEmail } },
    );

    // Now Alice creates a task **in the project** and posts a comment
    // that mentions Bob. The task has to belong to the project for
    // CommentMentionService to consider Bob a mentionable candidate
    // (collectMentionableUsers = task owner + project's effective space
    // members). Inline-creating from `/tasks` makes a personal task,
    // which silently drops the mention because Bob isn't in scope.
    const taskTitle = `Plan launch ${Date.now()}`;
    const taskCreate = await alicePage.request.post(`${BASE_URL}/tasks`, {
      headers: { "Content-Type": "application/ld+json" },
      data: {
        title: taskTitle,
        project: `/projects/${projectId}`,
      },
    });
    expect(taskCreate.ok(), "task POST should succeed").toBeTruthy();

    // /tasks shows every task the user can see, so the project task
    // will be in the list even though it wasn't created inline.
    await alicePage.goto(`${BASE_URL}/tasks`);

    const taskItem = alicePage.locator('[data-testid="task-item"]', {
      hasText: taskTitle,
    });
    await expect(taskItem).toBeVisible();

    // Open the task's comment panel. It's lazy-loaded; the toggle is
    // the "X comments" button on the row.
    await taskItem.locator('[data-testid="task-comments-toggle"]').click();

    // BlockNote's editable area is a [contenteditable=true] element
    // scoped to the comments panel.
    const panel = taskItem.locator('[data-testid="comments-panel"]');
    const bobLocalPart = bobEmail.split("@")[0];
    const expectedMention = `@${bobLocalPart}`;
    const commentText = `Heads up ${expectedMention} — please review.`;
    const editor = panel.locator('[contenteditable="true"]').first();
    await editor.click();
    await alicePage.keyboard.type(commentText);

    // Capture the POST /comments request body so we can verify the
    // markdown that BlockNote emitted.
    const [postRequest] = await Promise.all([
      alicePage.waitForRequest(
        (req) =>
          req.method() === "POST" && req.url().endsWith("/comments"),
      ),
      panel.locator('[data-testid="comment-submit"]').click(),
    ]);
    const postedBody = postRequest.postDataJSON()?.body ?? "";
    expect(postedBody, `Posted body should contain ${expectedMention}`).toContain(
      expectedMention,
    );

    // The rendered comment should highlight the mention as a chip.
    const renderedComment = panel
      .locator('[data-testid="comment-item"]')
      .filter({ hasText: bobLocalPart });
    await expect(renderedComment).toBeVisible();
    await expect(
      renderedComment.locator('[data-testid="comment-mention"]'),
    ).toContainText(expectedMention);

    // Bob should have one unread mention notification.
    await bobPage.reload();
    await bobPage.locator('[data-testid="notification-bell"]').click();
    const bellPanel = bobPage.locator('[data-testid="notification-bell-panel"]');
    await expect(bellPanel).toBeVisible();
    const item = bellPanel.locator('[data-testid="notification-item"]', {
      hasText: taskTitle,
    });
    await expect(item).toBeVisible();

    await aliceContext.close();
    await bobContext.close();
  });
});
