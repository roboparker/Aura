// @ts-check
const { test, expect } = require("@playwright/test");
const {
  BASE_URL,
  uniqueEmail: shared,
  registerAndSignIn,
} = require("./helpers");

const uniqueEmail = () => shared("custom-fields");

const openCustomFields = async (page) => {
  // Custom fields are space-owned now (#custom-fields-space): the full
  // definition manager lives at the space-level /custom-fields page (the
  // sidebar Settings section). A fresh signup lands with its personal space
  // active, which is where these fields get created.
  await page.goto(`${BASE_URL}/custom-fields`);
  await expect(page.getByTestId("custom-fields-manager")).toBeVisible();
};

test.describe("Custom field definitions (kind-aware, #227)", () => {
  test("create a select.single field in the drawer, edit it, and delete it", async ({
    page,
  }) => {
    await registerAndSignIn(page, uniqueEmail());
    await openCustomFields(page);

    // Open the create drawer.
    await page.getByTestId("custom-field-add").click();
    const sheet = page.getByTestId("custom-field-sheet");
    await expect(sheet).toBeVisible();

    // Switch kind to select; subtype defaults to "single". Options editor appears.
    await sheet.getByTestId("custom-field-kind-input").selectOption("select");
    const options = sheet.getByTestId("custom-field-options");
    await expect(options).toBeVisible();

    // Name + two options.
    await sheet.getByTestId("custom-field-name-input").fill("Severity");
    const optionInputs = sheet.getByTestId("custom-field-option-input");
    await optionInputs.first().fill("low");
    await sheet.getByTestId("custom-field-option-add").click();
    await optionInputs.nth(1).fill("high");

    // Toggle Required on, then save.
    await sheet.getByTestId("custom-field-required-input").click();
    await sheet.getByTestId("custom-field-save").click();

    const item = page.locator('[data-testid="custom-field-item"]', {
      hasText: "Severity",
    });
    await expect(item).toBeVisible();
    await expect(item.locator("text=select · single-select")).toBeVisible();
    await expect(item.locator("text=Required")).toBeVisible();

    // Edit — rename to "Priority" and drop the Required flag.
    await item.getByTestId("custom-field-edit").click();
    const editSheet = page.getByTestId("custom-field-sheet");
    await editSheet.getByTestId("custom-field-name-input").fill("Priority");
    await editSheet.getByTestId("custom-field-required-input").click();
    await editSheet.getByTestId("custom-field-save").click();

    const renamed = page.locator('[data-testid="custom-field-item"]', {
      hasText: "Priority",
    });
    await expect(renamed).toBeVisible();
    await expect(renamed.locator("text=Required")).toHaveCount(0);

    // Delete from the edit drawer — accept the confirm dialog.
    await renamed.getByTestId("custom-field-edit").click();
    page.once("dialog", (dialog) => dialog.accept());
    await page.getByTestId("custom-field-sheet").getByTestId("custom-field-delete").click();
    await expect(
      page.locator('[data-testid="custom-field-item"]', { hasText: "Priority" }),
    ).toHaveCount(0);
  });

  test("drawer surfaces per-kind config — money currency + footer pill", async ({
    page,
  }) => {
    await registerAndSignIn(page, uniqueEmail());
    await openCustomFields(page);

    await page.getByTestId("custom-field-add").click();
    const sheet = page.getByTestId("custom-field-sheet");

    // numeric > money → currency combobox shows up.
    await sheet.getByTestId("custom-field-kind-input").selectOption("numeric");
    await sheet.getByTestId("custom-field-subtype-input").selectOption("money");
    const currency = sheet.getByTestId("custom-field-currency-input");
    await expect(currency).toBeVisible();
    await currency.fill("EUR");
    await page.getByRole("option", { name: /Euro/i }).first().click();

    await sheet.getByTestId("custom-field-name-input").fill("Budget");
    // Money supports footer aggregations — pick the Sum pill.
    await sheet
      .getByTestId("custom-field-footer-pills")
      .getByRole("button", { name: "Sum" })
      .click();
    await sheet.getByTestId("custom-field-save").click();

    const item = page.locator('[data-testid="custom-field-item"]', {
      hasText: "Budget",
    });
    await expect(item).toBeVisible();
    await expect(item.locator("text=numeric · money")).toBeVisible();
    await expect(item.locator("text=SUM")).toBeVisible();
  });

  test("reference field offers the project target", async ({
    page,
  }) => {
    await registerAndSignIn(page, uniqueEmail());
    await openCustomFields(page);

    await page.getByTestId("custom-field-add").click();
    const sheet = page.getByTestId("custom-field-sheet");
    await sheet.getByTestId("custom-field-kind-input").selectOption("reference");

    // Target-type cards include Project (the strategy added this round).
    await sheet.getByTestId("custom-field-reference-project").click();
    await expect(sheet.getByTestId("custom-field-subtype-input")).toHaveValue(
      "project",
    );
    await sheet.getByTestId("custom-field-name-input").fill("Related project");
    await sheet.getByTestId("custom-field-save").click();

    const item = page.locator('[data-testid="custom-field-item"]', {
      hasText: "Related project",
    });
    await expect(item).toBeVisible();
    await expect(item.locator("text=reference · project")).toBeVisible();
  });
});

// API-side authorization (any space member can write), the reorder endpoint,
// fill/option stats, and the Loggable change feed (project-scoped) are covered
// by PHPUnit (CustomField* tests under api/tests/Api/). The change-log drawer
// is only shown in a project context, not on the space-level manager.
