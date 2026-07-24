// @ts-check
const { test, expect } = require("@playwright/test");
const {
  BASE_URL,
  uniqueEmail: shared,
  registerAndSignIn,
  fillDescription,
} = require("./helpers");

const uniqueEmail = () => shared("boards");

test.describe("Boards", () => {
  test("unauthenticated visitors are redirected to sign in", async ({ page }) => {
    await page.goto(`${BASE_URL}/boards`);
    await expect(page).toHaveURL(/\/signin/);
  });

  test("user can create, edit, and delete a board", async ({ page }) => {
    const email = uniqueEmail();
    await registerAndSignIn(page, email);

    await page.goto(`${BASE_URL}/boards`);
    await expect(page).toHaveTitle("Boards - Madori");
    await expect(page.locator("text=No boards yet")).toBeVisible();

    // Create — the form lives behind the "New board" toggle now.
    const title = `Launch plan ${Date.now()}`;
    await page.getByTestId("new-board-button").click();
    await page.fill("#title", title);
    await fillDescription(page, undefined, "Q3 marketing push");
    await page.click('button[type="submit"]');

    // Creating navigates to the new board's detail page; head back to
    // the list to verify it renders there.
    await expect(page).toHaveURL(/\/boards\/[a-f0-9-]+/);
    await page.goto(`${BASE_URL}/boards`);

    const item = page.locator('[data-testid="board-item"]', { hasText: title });
    await expect(item).toBeVisible();
    await expect(item.locator("text=Q3 marketing push")).toBeVisible();

    // Creator is auto-added as a member via BoardOwnerProcessor.
    await expect(item.locator('[data-testid="board-member"]')).toContainText(email);

    // Edit — update title and description
    await item.getByRole("button", { name: /^Edit$/ }).click();
    // Once editing, the form replaces the static title so scope by the sole
    // item on the page rather than by hasText which reads .value.
    const editing = page.locator('[data-testid="board-item"]').first();
    await editing.getByLabel("Title").fill(`${title} v2`);
    await fillDescription(page, editing, "Updated copy");
    await editing.getByRole("button", { name: /Save/i }).click();

    const updated = page.locator('[data-testid="board-item"]', { hasText: `${title} v2` });
    await expect(updated).toBeVisible();
    await expect(updated.locator("text=Updated copy")).toBeVisible();

    // Delete — accept the confirm dialog
    page.once("dialog", (dialog) => dialog.accept());
    await updated.getByRole("button", { name: /Delete "/ }).click();
    await expect(page.locator("text=No boards yet")).toBeVisible();
  });

  test("description supports markdown formatting", async ({ page }) => {
    await registerAndSignIn(page, uniqueEmail());
    await page.goto(`${BASE_URL}/boards`);
    await page.getByTestId("new-board-button").click();

    const title = `Markdown board ${Date.now()}`;
    await page.fill("#title", title);

    // Typing `# ` at the start of a BlockNote block turns it into a heading.
    // After the block-type switch we drop to a new block via Enter, then add a
    // bullet list via `- `.
    const editor = page.locator('#description [contenteditable="true"]').first();
    await editor.click();
    await page.keyboard.type("# Big heading");
    await page.keyboard.press("Enter");
    await page.keyboard.type("- first item");
    await page.keyboard.press("Enter");
    await page.keyboard.type("second item");

    await page.click('button[type="submit"]');

    // Creating navigates to the new board's detail page; head back to
    // the list to verify the rendered markdown there.
    await expect(page).toHaveURL(/\/boards\/[a-f0-9-]+/);
    await page.goto(`${BASE_URL}/boards`);

    const item = page.locator('[data-testid="board-item"]', { hasText: title });
    // MarkdownView renders headings as real <h1> elements and list items as <li>.
    await expect(item.locator("h1", { hasText: "Big heading" })).toBeVisible();
    await expect(item.locator("li", { hasText: "first item" })).toBeVisible();
    await expect(item.locator("li", { hasText: "second item" })).toBeVisible();
  });

  test("users only see boards they are members of", async ({ browser }) => {
    const aliceEmail = uniqueEmail();
    const bobEmail = uniqueEmail();
    const suffix = Date.now();
    const aliceProject = `Alice-private-${suffix}`;

    const aliceContext = await browser.newContext({ ignoreHTTPSErrors: true });
    const alicePage = await aliceContext.newPage();
    await registerAndSignIn(alicePage, aliceEmail);
    await alicePage.goto(`${BASE_URL}/boards`);
    await alicePage.getByTestId("new-board-button").click();
    await alicePage.fill("#title", aliceProject);
    await alicePage.click('button[type="submit"]');
    // Creating navigates to the new board's detail page; head back to
    // the list to verify it renders there.
    await expect(alicePage).toHaveURL(/\/boards\/[a-f0-9-]+/);
    await alicePage.goto(`${BASE_URL}/boards`);
    await expect(
      alicePage.locator('[data-testid="board-item"]', { hasText: aliceProject }),
    ).toBeVisible();

    const bobContext = await browser.newContext({ ignoreHTTPSErrors: true });
    const bobPage = await bobContext.newPage();
    await registerAndSignIn(bobPage, bobEmail);
    await bobPage.goto(`${BASE_URL}/boards`);
    await expect(bobPage.locator(`text=${aliceProject}`)).not.toBeVisible();
    await expect(bobPage.locator("text=No boards yet")).toBeVisible();

    await aliceContext.close();
    await bobContext.close();
  });

  test("the inline add-task row supports Tab between fields", async ({ page }) => {
    await registerAndSignIn(page, uniqueEmail());

    // Creating a board lands on its detail page, which opens on the Tasks
    // tab in list view by default.
    await page.goto(`${BASE_URL}/boards`);
    await page.getByTestId("new-board-button").click();
    const title = `Tab nav ${Date.now()}`;
    await page.fill("#title", title);
    await page.click('button[type="submit"]');
    await expect(page).toHaveURL(/\/boards\/[a-f0-9-]+/);

    // Open the inline add row in the default ("In progress") section.
    await page.getByTestId("board-new-task").click();
    const titleInput = page.getByTestId("board-new-task-title");
    await expect(titleInput).toBeVisible();
    await titleInput.click();
    await titleInput.fill("Keyboard task");

    // Tab walks the row in column order: title -> due -> assignees -> tags.
    await page.keyboard.press("Tab");
    await expect(
      page.getByRole("button", { name: "Due date for new task" }),
    ).toBeFocused();

    await page.keyboard.press("Tab");
    await expect(page.getByLabel('Assignees for "new task"')).toBeFocused();

    await page.keyboard.press("Tab");
    await expect(page.getByLabel('Tags for "new task"')).toBeFocused();

    // Tabbing away from a non-empty title keeps the row open; Enter on the
    // title still creates the task.
    await titleInput.press("Enter");
    await expect(
      page.locator('[data-testid="board-task-item"]', { hasText: "Keyboard task" }),
    ).toBeVisible();
  });

  test("tags staged in the add row are saved on the created task", async ({ page }) => {
    const email = uniqueEmail();
    await registerAndSignIn(page, email);

    // Tags are space-scoped now (#tags) — grab the user's personal space.
    const spacesRes = await page.request.get(`${BASE_URL}/spaces`, {
      headers: { Accept: "application/ld+json" },
    });
    expect(spacesRes.ok()).toBeTruthy();
    const spacesBody = await spacesRes.json();
    const spaceIri = (spacesBody.member ?? spacesBody["hydra:member"])[0]["@id"];

    // Seed a tag so the tags combobox has something to pick.
    const tagRes = await page.request.post(`${BASE_URL}/tags`, {
      headers: { "Content-Type": "application/ld+json" },
      data: { title: `urgent-${Date.now()}`, color: "#ef4444", space: spaceIri },
    });
    expect(tagRes.ok()).toBeTruthy();
    const tag = await tagRes.json();

    await page.goto(`${BASE_URL}/boards`);
    await page.getByTestId("new-board-button").click();
    const title = `Tag staging ${Date.now()}`;
    await page.fill("#title", title);
    await page.click('button[type="submit"]');
    await expect(page).toHaveURL(/\/boards\/[a-f0-9-]+/);

    await page.getByTestId("board-new-task").click();
    const titleInput = page.getByTestId("board-new-task-title");
    await titleInput.fill("Tagged task");

    // Stage a tag, then submit from the title input.
    const tagsInput = page.getByLabel('Tags for "new task"');
    await tagsInput.click();
    await page.getByRole("option", { name: tag.title }).click();
    await titleInput.press("Enter");

    const item = page.locator('[data-testid="board-task-item"]', {
      hasText: "Tagged task",
    });
    await expect(item).toBeVisible();
    // The staged tag persisted onto the new task's row.
    await expect(item.locator('[data-testid="task-tag"]', { hasText: tag.title })).toBeVisible();
  });

  test("timeline tab renders its empty state until a start field is set", async ({ page }) => {
    const email = uniqueEmail();
    await registerAndSignIn(page, email);

    // Create a board and land on its detail page.
    await page.goto(`${BASE_URL}/boards`);
    const title = `Timeline board ${Date.now()}`;
    await page.getByTestId("new-board-button").click();
    await page.fill("#title", title);
    await page.click('button[type="submit"]');
    await expect(page).toHaveURL(/\/boards\/[a-f0-9-]+/);

    // The Timeline tab mounts the Gantt view (#timeline). With no start field
    // configured, it must render the opt-in empty state rather than throw.
    await page.getByTestId("board-timeline-tab").click();
    await expect(page.locator("text=Timeline isn't set up yet")).toBeVisible();

    // The picker for turning it on lives under Settings.
    await page.getByTestId("board-settings-tab").click();
    await expect(page.getByTestId("timeline-start-field")).toBeVisible();
  });

  // Removed "account menu shows Boards link" — Boards is no
  // longer a top-level sidebar link after the sidebar redesign
  // (#nav-refresh). The /boards page is still reachable directly
  // and covered by the other tests in this file.
});
