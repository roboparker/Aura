// @ts-check
const { test, expect } = require("@playwright/test");
const {
  BASE_URL,
  uniqueEmail: shared,
  registerAndSignIn,
  createTaskInline,
  openAccountMenu,
} = require("./helpers");

const uniqueEmail = () => shared("tasks");

test.describe("Tasks", () => {
  test("unauthenticated visitors are redirected to sign in", async ({ page }) => {
    await page.goto(`${BASE_URL}/tasks`);
    await expect(page).toHaveURL(/\/signin/);
  });

  test("user can create, complete, and delete a task", async ({ page }) => {
    await registerAndSignIn(page, uniqueEmail());

    await page.goto(`${BASE_URL}/tasks`);
    await expect(page).toHaveTitle("Tasks - Aura");
    // Empty state: only the new-task input row, no real task rows.
    await expect(page.locator('[data-testid="task-item"]')).toHaveCount(0);

    // Create — title + staged description via the inline new-task row.
    const title = `Buy groceries ${Date.now()}`;
    await createTaskInline(page, title, { description: "Milk, eggs, bread" });

    const item = page.locator('[data-testid="task-item"]', { hasText: title });
    await expect(item.locator("text=Milk, eggs, bread")).toBeVisible();

    // New-task input clears after submit and is ready for the next entry.
    await expect(
      page.locator('[data-testid="new-task-title-input"]'),
    ).toHaveValue("");

    // Complete. Wait for the optimistic-update PATCH to finish before
    // issuing the next click — without this barrier the in-flight PATCH
    // races the `uncheck()` and Playwright sees "did not change its
    // state" intermittently. This was tried in #142, removed in 1de7985,
    // and is being restored because the underlying race still bites.
    const completeResponse = page.waitForResponse(
      (res) =>
        res.request().method() === "PATCH" &&
        res.url().includes("/tasks/") &&
        res.ok(),
    );
    await item.locator('input[type="checkbox"]').check();
    await completeResponse;
    await expect(item.locator(`text=${title}`)).toHaveClass(/line-through/);

    // Uncomplete
    const uncompleteResponse = page.waitForResponse(
      (res) =>
        res.request().method() === "PATCH" &&
        res.url().includes("/tasks/") &&
        res.ok(),
    );
    await item.locator('input[type="checkbox"]').uncheck();
    await uncompleteResponse;
    await expect(item.locator(`text=${title}`)).not.toHaveClass(/line-through/);

    // Delete (trash icon button — accessible name is `Delete "<title>"`)
    await item.getByRole("button", { name: /^Delete "/ }).click();
    await expect(item).toHaveCount(0);
  });

  test("users only see their own tasks", async ({ browser }) => {
    const aliceEmail = uniqueEmail();
    const bobEmail = uniqueEmail();

    // Alice creates a task
    const aliceContext = await browser.newContext({ ignoreHTTPSErrors: true });
    const alicePage = await aliceContext.newPage();
    await registerAndSignIn(alicePage, aliceEmail);
    await alicePage.goto(`${BASE_URL}/tasks`);
    const aliceTitle = `Alice secret ${Date.now()}`;
    await createTaskInline(alicePage, aliceTitle);

    // Bob signs in in an isolated context and should not see Alice's task
    const bobContext = await browser.newContext({ ignoreHTTPSErrors: true });
    const bobPage = await bobContext.newPage();
    await registerAndSignIn(bobPage, bobEmail);
    await bobPage.goto(`${BASE_URL}/tasks`);
    await expect(bobPage.locator(`text=${aliceTitle}`)).not.toBeVisible();
    await expect(bobPage.locator('[data-testid="task-item"]')).toHaveCount(0);

    await aliceContext.close();
    await bobContext.close();
  });

  test("account menu shows Tasks link when authenticated", async ({ page }) => {
    await registerAndSignIn(page, uniqueEmail());
    await openAccountMenu(page);
    // `text=Tasks` would substring-match the new "My Tasks" entry too —
    // pin to an exact-name role lookup so each link is targeted on its own.
    const tasksLink = page.locator("nav").getByRole("link", {
      name: "Tasks",
      exact: true,
    });
    await expect(tasksLink).toBeVisible();
    await tasksLink.click();
    await expect(page).toHaveURL(/\/tasks$/);
  });

  test("setting a weekly recurrence shows a repeat icon and spawns the next occurrence on completion", async ({
    page,
  }) => {
    await registerAndSignIn(page, uniqueEmail());

    // Seed a task with a known due date via the API so the recurrence-advance
    // assertion below can predict the next occurrence's date deterministically.
    const title = `Water plants ${Date.now()}`;
    const startDate = new Date();
    startDate.setDate(startDate.getDate() - 3);
    startDate.setHours(0, 0, 0, 0);
    const createRes = await page.request.post(`${BASE_URL}/tasks`, {
      headers: { "Content-Type": "application/ld+json" },
      data: { title, dueDate: startDate.toISOString() },
    });
    expect(createRes.ok()).toBeTruthy();

    await page.goto(`${BASE_URL}/tasks`);
    const item = page.locator('[data-testid="task-item"]', { hasText: title });
    await expect(item).toBeVisible();

    // Open picker, set weekly recurrence with interval 2
    await item.locator('[data-testid="task-due-date"]').click();
    await page
      .locator('[data-testid="task-due-date-recurrence-frequency"]')
      .selectOption("weekly");
    await page
      .locator('[data-testid="task-due-date-recurrence-interval"]')
      .fill("2");
    // Close popover by clicking outside
    await page.keyboard.press("Escape");

    // Repeat icon now shows on the row
    await expect(
      item.locator('[data-testid="task-due-date-repeat-icon"]'),
    ).toBeVisible();

    // Complete the task — backend clones the next occurrence; frontend
    // refetches because the toggled task carries a recurrence rule.
    await item.locator('input[type="checkbox"]').check();

    // Two rows now share the title — one completed (the original) and one
    // pending (the next occurrence, due 14 days after the original date).
    const allRows = page.locator('[data-testid="task-item"]', { hasText: title });
    await expect(allRows).toHaveCount(2);

    // The new row also shows the recurrence icon (rule carries over).
    await expect(
      allRows
        .filter({ hasNot: page.locator("input[type=checkbox]:checked") })
        .locator('[data-testid="task-due-date-repeat-icon"]'),
    ).toBeVisible();
  });

  test("quick-pick sets a due date and shows today/overdue indicators", async ({
    page,
  }) => {
    // Two tasks: one we mark overdue via the API (yesterday), one we set to
    // today via the UI quick-pick. Verifies styling + filter chip count.
    await registerAndSignIn(page, uniqueEmail());
    await page.goto(`${BASE_URL}/tasks`);

    const todayTitle = `Today task ${Date.now()}`;
    await createTaskInline(page, todayTitle);

    // Open the picker on the new "today" task and click the Today quick-pick.
    const todayItem = page.locator('[data-testid="task-item"]', {
      hasText: todayTitle,
    });
    await todayItem.locator('[data-testid="task-due-date-add"]').click();
    await page.locator('[data-testid="task-due-date-quick-today"]').click();
    const todayCell = todayItem.locator('[data-testid="task-due-date"]');
    await expect(todayCell).toHaveAttribute("data-status", "today");

    // Create an overdue task by patching dueDate to yesterday via the API.
    const overdueTitle = `Overdue task ${Date.now()}`;
    const createRes = await page.request.post(`${BASE_URL}/tasks`, {
      headers: { "Content-Type": "application/ld+json" },
      data: { title: overdueTitle },
    });
    expect(createRes.ok()).toBeTruthy();
    const created = await createRes.json();
    const yesterday = new Date();
    yesterday.setDate(yesterday.getDate() - 1);
    yesterday.setHours(0, 0, 0, 0);
    const patchRes = await page.request.patch(`${BASE_URL}${created["@id"]}`, {
      headers: { "Content-Type": "application/merge-patch+json" },
      data: { dueDate: yesterday.toISOString() },
    });
    expect(patchRes.ok()).toBeTruthy();
    await page.reload();

    const overdueItem = page.locator('[data-testid="task-item"]', {
      hasText: overdueTitle,
    });
    const overdueCell = overdueItem.locator('[data-testid="task-due-date"]');
    await expect(overdueCell).toHaveAttribute("data-status", "overdue");
    await expect(
      overdueItem.locator('[data-testid="task-due-date-overdue-icon"]'),
    ).toBeVisible();

    // Filter chip reports a count and toggles the list.
    const filter = page.locator('[data-testid="overdue-filter-toggle"]');
    await expect(
      filter.locator('[data-testid="overdue-filter-count"]'),
    ).toHaveText("1");
    await filter.click();
    await expect(overdueItem).toBeVisible();
    await expect(todayItem).not.toBeVisible();

    // Completing the overdue task strips the overdue status (no longer a
    // missed deadline once the work is done). Clear the overdue filter
    // *before* completing so the row stays mounted while .check() waits
    // for the controlled checkbox to flip — completing while filtered
    // would yank the row out of view and time the check out.
    await filter.click();
    await expect(todayItem).toBeVisible();
    await overdueItem.locator('input[type="checkbox"]').check();
    await expect(overdueCell).toHaveAttribute("data-status", "none");
  });

  test("user can set reminders on a task with a due date", async ({ page }) => {
    await registerAndSignIn(page, uniqueEmail());

    const title = `Take medication ${Date.now()}`;
    const due = new Date();
    due.setDate(due.getDate() + 1);
    const createRes = await page.request.post(`${BASE_URL}/tasks`, {
      headers: { "Content-Type": "application/ld+json" },
      data: { title, dueDate: due.toISOString() },
    });
    expect(createRes.ok()).toBeTruthy();

    await page.goto(`${BASE_URL}/tasks`);
    const item = page.locator('[data-testid="task-item"]', { hasText: title });
    await expect(item).toBeVisible();

    // Open the date popover and tick "1 hour before". The reminder section
    // sits below the calendar + recurrence picker, so on a 720px viewport
    // it scrolls within the popover (PopoverContent caps its own height
    // and lets overflow scroll internally).
    await item.locator('[data-testid="task-due-date"]').click();
    const reminderCheckbox = page.locator(
      '[data-testid="task-due-date-reminder-1h"]',
    );
    await reminderCheckbox.scrollIntoViewIfNeeded();
    await reminderCheckbox.check();
    await page.keyboard.press("Escape");

    // Bell icon appears next to the date now that a reminder is set.
    await expect(
      item.locator('[data-testid="task-due-date-reminder-icon"]'),
    ).toBeVisible();

    // The persisted task carries the reminder offset.
    const list = await page.request.get(`${BASE_URL}/tasks`, {
      headers: { Accept: "application/ld+json" },
    });
    const json = await list.json();
    const persisted = (json.member ?? json["hydra:member"] ?? []).find(
      (t) => t.title === title,
    );
    expect(persisted.reminders).toEqual(["1h"]);
  });

  test("user can reorder tasks via keyboard drag", async ({ page }) => {
    // dnd-kit's KeyboardSensor is deterministic across browsers, unlike
    // pointer-based drag which is flaky with dnd-kit's distance constraint.
    // Space picks up the grip, ArrowDown moves it, Space drops.
    await registerAndSignIn(page, uniqueEmail());
    await page.goto(`${BASE_URL}/tasks`);

    // New tasks go to the top, so creating in order A, B, C produces list C, B, A.
    // Keep suffixes unique in case the browser uses a cached network response.
    const suffix = Date.now();
    const titles = [`A-${suffix}`, `B-${suffix}`, `C-${suffix}`];
    for (const t of titles) {
      await createTaskInline(page, t);
    }

    // Verify initial order (newest first): C, B, A
    const listItems = page
      .locator('[data-testid="task-item"] [data-testid="task-title"]')
      .first();
    await expect(listItems).toHaveText(titles[2]);

    // Grab the grip on the top item (C) and move it down twice to position 3.
    // Use locator.press() to focus+press atomically (more reliable in CI than
    // focus() + keyboard.press), and wait briefly between keys so dnd-kit's
    // drag state has time to update between Space/Arrow events.
    const topGrip = page
      .locator('[data-testid="task-item"]')
      .first()
      .getByRole("button", { name: /Drag to reorder/ });
    await topGrip.press("Space");
    await page.waitForTimeout(100);
    await page.keyboard.press("ArrowDown");
    await page.waitForTimeout(100);
    await page.keyboard.press("ArrowDown");
    await page.waitForTimeout(100);
    await page.keyboard.press("Space");

    // Now the order should be B, A, C — verify via the top item
    await expect(
      page
        .locator('[data-testid="task-item"]')
        .first()
        .locator('[data-testid="task-title"]'),
    ).toHaveText(titles[1]);

    // Reloading should preserve the server-persisted order
    await page.reload();
    await expect(
      page
        .locator('[data-testid="task-item"]')
        .first()
        .locator('[data-testid="task-title"]'),
    ).toHaveText(titles[1]);
    await expect(
      page
        .locator('[data-testid="task-item"]')
        .last()
        .locator('[data-testid="task-title"]'),
    ).toHaveText(titles[2]);
  });
});
