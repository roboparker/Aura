// @ts-check
const { test, expect } = require("@playwright/test");
const {
  BASE_URL,
  uniqueEmail: shared,
  registerAndSignIn,
  fillDescription,
  openAccountMenu,
} = require("./helpers");

const uniqueEmail = () => shared("projects");

test.describe("Projects", () => {
  test("unauthenticated visitors are redirected to sign in", async ({ page }) => {
    await page.goto(`${BASE_URL}/projects`);
    await expect(page).toHaveURL(/\/signin/);
  });

  test("user can create, edit, and delete a project", async ({ page }) => {
    const email = uniqueEmail();
    await registerAndSignIn(page, email);

    await page.goto(`${BASE_URL}/projects`);
    await expect(page).toHaveTitle("Projects - Aura");
    await expect(page.locator("text=No projects yet")).toBeVisible();

    // Create
    const title = `Launch plan ${Date.now()}`;
    await page.fill("#title", title);
    await fillDescription(page, undefined, "Q3 marketing push");
    await page.click('button[type="submit"]');

    const item = page.locator('[data-testid="project-item"]', { hasText: title });
    await expect(item).toBeVisible();
    await expect(item.locator("text=Q3 marketing push")).toBeVisible();

    // Creator is auto-added as a member via ProjectOwnerProcessor.
    await expect(item.locator('[data-testid="project-member"]')).toContainText(email);

    // Form clears after submit
    await expect(page.locator("#title")).toHaveValue("");

    // Edit — update title and description
    await item.getByRole("button", { name: /^Edit$/ }).click();
    // Once editing, the form replaces the static title so scope by the sole
    // item on the page rather than by hasText which reads .value.
    const editing = page.locator('[data-testid="project-item"]').first();
    await editing.getByLabel("Title").fill(`${title} v2`);
    await fillDescription(page, editing, "Updated copy");
    await editing.getByRole("button", { name: /Save/i }).click();

    const updated = page.locator('[data-testid="project-item"]', { hasText: `${title} v2` });
    await expect(updated).toBeVisible();
    await expect(updated.locator("text=Updated copy")).toBeVisible();

    // Delete — accept the confirm dialog
    page.once("dialog", (dialog) => dialog.accept());
    await updated.getByRole("button", { name: /Delete "/ }).click();
    await expect(page.locator("text=No projects yet")).toBeVisible();
  });

  test("description supports markdown formatting", async ({ page }) => {
    await registerAndSignIn(page, uniqueEmail());
    await page.goto(`${BASE_URL}/projects`);

    const title = `Markdown project ${Date.now()}`;
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

    const item = page.locator('[data-testid="project-item"]', { hasText: title });
    // MarkdownView renders headings as real <h1> elements and list items as <li>.
    await expect(item.locator("h1", { hasText: "Big heading" })).toBeVisible();
    await expect(item.locator("li", { hasText: "first item" })).toBeVisible();
    await expect(item.locator("li", { hasText: "second item" })).toBeVisible();
  });

  test("users only see projects they are members of", async ({ browser }) => {
    const aliceEmail = uniqueEmail();
    const bobEmail = uniqueEmail();
    const suffix = Date.now();
    const aliceProject = `Alice-private-${suffix}`;

    const aliceContext = await browser.newContext({ ignoreHTTPSErrors: true });
    const alicePage = await aliceContext.newPage();
    await registerAndSignIn(alicePage, aliceEmail);
    await alicePage.goto(`${BASE_URL}/projects`);
    await alicePage.fill("#title", aliceProject);
    await alicePage.click('button[type="submit"]');
    await expect(
      alicePage.locator('[data-testid="project-item"]', { hasText: aliceProject }),
    ).toBeVisible();

    const bobContext = await browser.newContext({ ignoreHTTPSErrors: true });
    const bobPage = await bobContext.newPage();
    await registerAndSignIn(bobPage, bobEmail);
    await bobPage.goto(`${BASE_URL}/projects`);
    await expect(bobPage.locator(`text=${aliceProject}`)).not.toBeVisible();
    await expect(bobPage.locator("text=No projects yet")).toBeVisible();

    await aliceContext.close();
    await bobContext.close();
  });

  test("account menu shows Projects link when authenticated", async ({ page }) => {
    await registerAndSignIn(page, uniqueEmail());
    await openAccountMenu(page);
    await expect(page.locator("nav >> text=Projects")).toBeVisible();
    await page.locator("nav >> text=Projects").click();
    await expect(page).toHaveURL(/\/projects/);
  });
});
