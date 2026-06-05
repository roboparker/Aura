// @ts-check
const { test, expect } = require("@playwright/test");
const { BASE_URL, uniqueEmail, registerAndSignIn } = require("./helpers");

test.describe("Search page + autocomplete", () => {
  test("autocomplete shows live results and View-all links to /search", async ({ page }) => {
    const email = uniqueEmail("search-ac");
    await registerAndSignIn(page, email);

    // Seed two tasks via the API so we don't depend on the inline UI
    // for setup — that part is covered by tasks.spec.js.
    const ldHeaders = { "Content-Type": "application/ld+json" };
    await page.request.post(`${BASE_URL}/tasks`, {
      headers: ldHeaders,
      data: { title: "Refactor authentication module" },
    });
    await page.request.post(`${BASE_URL}/tasks`, {
      headers: ldHeaders,
      data: { title: "Buy groceries" },
    });

    await page.goto(`${BASE_URL}/account`);
    const searchInput = page.getByTestId("navbar-search");
    await searchInput.fill("authentication");

    const dropdown = page.getByTestId("search-autocomplete");
    await expect(dropdown).toBeVisible();
    await expect(
      dropdown.getByTestId("search-autocomplete-item").first(),
    ).toContainText("authentication");

    await page.getByTestId("search-autocomplete-view-all").click();
    await expect(page).toHaveURL(/\/search\?q=authentication/);
    await expect(page.getByTestId("search-result-count")).toContainText("1 result");
    // The matched word should render inside a <mark>.
    await expect(
      page.locator('[data-testid="search-result-row"] mark'),
    ).toContainText(/authentication/i);
  });

  test("filter chips narrow results and round-trip through the URL", async ({ page }) => {
    const email = uniqueEmail("search-filters");
    await registerAndSignIn(page, email);

    const ldHeaders = { "Content-Type": "application/ld+json" };
    const open = await page.request.post(`${BASE_URL}/tasks`, {
      headers: ldHeaders,
      data: { title: "Open release checklist" },
    });
    expect(open.ok()).toBeTruthy();
    const completed = await page.request.post(`${BASE_URL}/tasks`, {
      headers: ldHeaders,
      data: { title: "Done release retro" },
    });
    expect(completed.ok()).toBeTruthy();
    const completedTask = await completed.json();
    // Mark the second task completed so the status filter has something
    // to discriminate against.
    const markDone = await page.request.patch(
      `${BASE_URL}${completedTask["@id"]}`,
      {
        headers: { "Content-Type": "application/merge-patch+json" },
        data: { completedOn: new Date().toISOString() },
      },
    );
    expect(markDone.ok()).toBeTruthy();

    await page.goto(`${BASE_URL}/search?q=release`);
    await expect(page.getByTestId("search-result-count")).toContainText("2 results");

    // Filter to open-only — the URL must reflect the status and the
    // completed task must drop from the list.
    await page.getByTestId("search-status-open").click();
    await expect(page).toHaveURL(/status=open/);
    await expect(page.getByTestId("search-result-count")).toContainText("1 result");
    await expect(page.getByTestId("search-results")).toContainText("Open release checklist");
    await expect(page.getByTestId("search-results")).not.toContainText("Done release retro");

    // Active chip is rendered and clearing it restores both results.
    await expect(page.getByTestId("search-chip-status")).toBeVisible();
    await page.getByTestId("search-chip-status").getByRole("button").click();
    await expect(page).not.toHaveURL(/status=/);
    await expect(page.getByTestId("search-result-count")).toContainText("2 results");
  });

  test("assignee filter narrows results to a single user", async ({ page }) => {
    const email = uniqueEmail("search-assignee");
    await registerAndSignIn(page, email);

    // Look up the current user's IRI so we can assign the seeded task to
    // ourselves via the API.
    const meRes = await page.request.get(`${BASE_URL}/me/assignable-users`);
    expect(meRes.ok()).toBeTruthy();
    const meData = await meRes.json();
    const members = meData["hydra:member"] ?? meData.member ?? [];
    const self = members.find((m) => m.email === email);
    expect(self).toBeTruthy();

    const ldHeaders = { "Content-Type": "application/ld+json" };
    const assigned = await page.request.post(`${BASE_URL}/tasks`, {
      headers: ldHeaders,
      data: { title: "Assigned launch plan", assignees: [self["@id"]] },
    });
    expect(assigned.ok()).toBeTruthy();
    const unassigned = await page.request.post(`${BASE_URL}/tasks`, {
      headers: ldHeaders,
      data: { title: "Unassigned launch backlog" },
    });
    expect(unassigned.ok()).toBeTruthy();

    await page.goto(`${BASE_URL}/search?q=launch`);
    await expect(page.getByTestId("search-result-count")).toContainText("2 results");

    await page
      .getByTestId("search-assignee")
      .selectOption({ value: self["@id"] });
    await expect(page).toHaveURL(/assignee=/);
    await expect(page.getByTestId("search-result-count")).toContainText("1 result");
    await expect(page.getByTestId("search-results")).toContainText("Assigned launch plan");
    await expect(page.getByTestId("search-results")).not.toContainText("Unassigned launch backlog");

    await expect(page.getByTestId("search-chip-assignee")).toBeVisible();
    await page.getByTestId("search-chip-assignee").getByRole("button").click();
    await expect(page).not.toHaveURL(/assignee=/);
    await expect(page.getByTestId("search-result-count")).toContainText("2 results");
  });

  test("kind tabs switch between tasks, projects, and discussions", async ({
    page,
  }) => {
    const email = uniqueEmail("search-kinds");
    await registerAndSignIn(page, email);

    const ldHeaders = { "Content-Type": "application/ld+json" };
    const stamp = Date.now();
    const projectTitle = `Pinwheel project ${stamp}`;
    const taskTitle = `Pinwheel task ${stamp}`;
    const discussionTitle = `Pinwheel discussion ${stamp}`;

    const projectRes = await page.request.post(`${BASE_URL}/projects`, {
      headers: ldHeaders,
      data: { title: projectTitle, description: "Spinning the wheel." },
    });
    expect(projectRes.ok()).toBeTruthy();

    const taskRes = await page.request.post(`${BASE_URL}/tasks`, {
      headers: ldHeaders,
      data: { title: taskTitle },
    });
    expect(taskRes.ok()).toBeTruthy();

    const spacesRes = await page.request.get(`${BASE_URL}/spaces`, {
      headers: { Accept: "application/ld+json" },
    });
    const spacesData = await spacesRes.json();
    const personalSpace = (spacesData.member ?? spacesData["hydra:member"] ?? [])
      .find((s) => s.isPersonal);
    expect(personalSpace).toBeDefined();

    const discussionRes = await page.request.post(`${BASE_URL}/discussions`, {
      headers: ldHeaders,
      data: {
        space: personalSpace["@id"],
        title: discussionTitle,
        body: "Round and round it goes.",
        category: "general",
      },
    });
    expect(discussionRes.ok()).toBeTruthy();

    await page.goto(`${BASE_URL}/search?q=Pinwheel`);
    // Default tab is Tasks.
    await expect(page.getByTestId("search-results")).toContainText(taskTitle);
    await expect(page.getByTestId("search-results")).not.toContainText(
      projectTitle,
    );

    await page.getByTestId("search-kind-projects").click();
    await expect(page).toHaveURL(/kind=projects/);
    await expect(page.getByTestId("search-results")).toContainText(projectTitle);
    await expect(page.getByTestId("search-results")).not.toContainText(
      taskTitle,
    );

    await page.getByTestId("search-kind-discussions").click();
    await expect(page).toHaveURL(/kind=discussions/);
    await expect(page.getByTestId("search-results")).toContainText(
      discussionTitle,
    );

    // Switching back to the Tasks tab drops the kind param from the URL
    // (tasks is the default) and re-shows the task hit.
    await page.getByTestId("search-kind-tasks").click();
    await expect(page).not.toHaveURL(/kind=/);
    await expect(page.getByTestId("search-results")).toContainText(taskTitle);
  });

  test("annotates a task that matched only in a comment", async ({ page }) => {
    await registerAndSignIn(page, uniqueEmail("search-prov"));

    const ldHeaders = { "Content-Type": "application/ld+json" };
    const taskRes = await page.request.post(`${BASE_URL}/tasks`, {
      headers: ldHeaders,
      data: { title: "Quarterly planning" },
    });
    expect(taskRes.ok()).toBeTruthy();
    const task = await taskRes.json();
    const commentRes = await page.request.post(`${BASE_URL}/comments`, {
      headers: ldHeaders,
      data: { task: task["@id"], body: "We should discuss the zephyrwidget rollout." },
    });
    expect(commentRes.ok()).toBeTruthy();

    // The term lives only in the comment, so the row must surface the
    // "matched in comment" provenance line (not just the title).
    await page.goto(`${BASE_URL}/search?q=zephyrwidget`);
    await expect(page.getByTestId("search-result-count")).toContainText("1 result");
    await expect(page.getByTestId("search-results")).toContainText("matched in comment");
  });

  test("sort toggle and scope widen round-trip through the URL", async ({ page }) => {
    await registerAndSignIn(page, uniqueEmail("search-sort"));

    await page.goto(`${BASE_URL}/search?q=anything`);
    await page.getByTestId("search-sort").getByText("Most recent").click();
    await expect(page).toHaveURL(/sort=recent/);

    // Scope defaults to the active space; the chip widens to all spaces.
    await page.getByTestId("search-scope").click();
    await expect(page).toHaveURL(/scope=all/);
  });

  test("Cmd/Ctrl-K opens the search palette and runs a query", async ({ page }) => {
    await registerAndSignIn(page, uniqueEmail("search-palette"));
    await page.goto(`${BASE_URL}/account`);

    await page.keyboard.press("ControlOrMeta+KeyK");
    const overlay = page.getByTestId("search-overlay");
    await expect(overlay).toBeVisible();

    await page.getByTestId("search-overlay-input").fill("rocketship");
    await page.keyboard.press("Enter");
    await expect(page).toHaveURL(/\/search\?q=rocketship/);
  });

  test("empty state offers to create a task from the query", async ({ page }) => {
    await registerAndSignIn(page, uniqueEmail("search-empty"));

    await page.goto(`${BASE_URL}/search?q=zzznotathing999`);
    const empty = page.getByTestId("search-empty");
    await expect(empty).toBeVisible();
    await expect(empty).toContainText("Create a task");
  });
});
