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
});
