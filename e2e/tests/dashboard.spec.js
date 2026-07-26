// @ts-check
const { test, expect } = require("@playwright/test");
const { BASE_URL, uniqueEmail: shared, registerAndSignIn } = require("./helpers");

const uniqueEmail = () => shared("dashboard");

/**
 * Configurable dashboard (#759).
 *
 * The permission rules — who may see which widget, the admin-reserved
 * categories, config-dependent categories — are covered at the API level by
 * `App\Tests\Api\DashboardWidgetTest`, which can set up multi-user spaces far
 * more cheaply than a browser can. What that can't tell us is whether the page
 * actually drives any of it.
 *
 * So this covers the admin's full round trip through the UI: empty state → add
 * from the catalog → configure → see the config render → remove. That's the
 * path a plugin author's widget will travel, and it crosses a dialog, a drawer,
 * and two optimistic refetches — the parts most likely to break silently.
 *
 * A freshly registered user is an admin of their own Private space, so no extra
 * setup is needed to reach the editing controls.
 */
test.describe("Dashboard", () => {
  test("unauthenticated visitors are redirected to sign in", async ({ page }) => {
    await page.goto(`${BASE_URL}/dashboard`);
    await expect(page).toHaveURL(/\/signin/);
  });

  test("an admin can add, configure, and remove a widget", async ({ page }) => {
    await registerAndSignIn(page, uniqueEmail());

    await page.goto(`${BASE_URL}/dashboard`);
    await expect(page.getByRole("heading", { name: "Dashboard" })).toBeVisible();

    // A brand-new space starts with nothing on it.
    await expect(page.getByText("This dashboard is empty")).toBeVisible();

    // --- add, from the server-driven catalog -------------------------------
    await page.getByRole("button", { name: /Add your first widget/ }).click();
    await expect(page.getByRole("heading", { name: "Add a widget" })).toBeVisible();

    await page.getByTestId("add-widget-notes.markdown").click();

    const note = page.getByTestId("widget-notes.markdown");
    await expect(note).toBeVisible();
    // The empty state is gone, so the layout actually re-read from the server.
    await expect(page.getByText("This dashboard is empty")).toBeHidden();

    // The picker stays open for adding several in a row.
    await page.getByRole("button", { name: /^Done$/ }).click();

    // --- configure ----------------------------------------------------------
    await note.getByRole("button", { name: "Widget options" }).click();
    await page.getByRole("menuitem", { name: /Configure/ }).click();

    const heading = `Team note ${Date.now()}`;
    await page.locator("#wcfg-title").fill(heading);
    await page.locator("#wcfg-body").fill("We ship on Thursdays.");
    await page.getByRole("button", { name: /^Save$/ }).click();

    // Config round-tripped and the widget renders it — this is the whole
    // point of the config being stored server-side rather than in the client.
    await expect(note.getByText(heading)).toBeVisible();
    await expect(note.getByText("We ship on Thursdays.")).toBeVisible();

    // --- a second widget, to prove the catalog isn't a one-shot -------------
    await page.getByRole("button", { name: /^Add widget$/ }).click();
    await page.getByTestId("add-widget-boards.list").click();
    await expect(page.getByTestId("widget-boards.list")).toBeVisible();
    await page.getByRole("button", { name: /^Done$/ }).click();

    // --- remove -------------------------------------------------------------
    await note.getByRole("button", { name: "Widget options" }).click();
    await page.getByRole("menuitem", { name: /Remove/ }).click();

    await expect(page.getByTestId("widget-notes.markdown")).toHaveCount(0);
    // The other widget is untouched — remove hit one row, not the layout.
    await expect(page.getByTestId("widget-boards.list")).toBeVisible();
  });
});
