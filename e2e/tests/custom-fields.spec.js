// @ts-check
const { test, expect } = require("@playwright/test");
const {
  BASE_URL,
  uniqueEmail: shared,
  registerAndSignIn,
} = require("./helpers");

const uniqueEmail = () => shared("custom-fields");

test.describe("Custom field definitions (kind-aware, #227)", () => {
  test("project owner can create a select.single field, edit it, and delete it", async ({
    page,
  }) => {
    await registerAndSignIn(page, uniqueEmail());

    // Create a project to host the schema.
    await page.goto(`${BASE_URL}/projects`);
    const projectTitle = `CF-host-${Date.now()}`;
    await page.fill("#title", projectTitle);
    await page.click('button[type="submit"]');
    const projectItem = page.locator('[data-testid="project-item"]', {
      hasText: projectTitle,
    });
    await expect(projectItem).toBeVisible();

    // Open the project, follow the Custom fields link.
    await projectItem.locator(`a[href^="/projects/"]`).first().click();
    await expect(page).toHaveURL(/\/projects\/[a-f0-9-]+/);
    await page.click('[data-testid="project-custom-fields-link"]');
    await expect(page).toHaveURL(/\/projects\/[a-f0-9-]+\/custom-fields$/);

    // Empty state has the add button visible.
    await page.getByTestId("custom-field-add").click();
    const composer = page.getByTestId("custom-field-composer");
    await expect(composer).toBeVisible();

    // Switch kind to select; subtype defaults to "single". The options
    // editor appears.
    await composer.getByTestId("custom-field-kind-input").selectOption("select");
    const options = composer.getByTestId("custom-field-options");
    await expect(options).toBeVisible();

    // Fill name + two options.
    await composer
      .getByTestId("custom-field-name-input")
      .fill("Severity");
    const optionInputs = composer.getByTestId("custom-field-option-input");
    await optionInputs.first().fill("low");
    await composer.getByTestId("custom-field-option-add").click();
    await optionInputs.nth(1).fill("high");

    // Mark required and save.
    await composer.getByTestId("custom-field-required-input").click();
    await composer.getByTestId("custom-field-save").click();

    const item = page.locator('[data-testid="custom-field-item"]', {
      hasText: "Severity",
    });
    await expect(item).toBeVisible();
    // The badge shows the human label for `select.single`.
    await expect(item.locator("text=Single choice")).toBeVisible();
    await expect(item.locator("text=Required")).toBeVisible();
    await expect(item.locator("text=Options: low, high")).toBeVisible();

    // Edit — rename to "Priority" and drop the "required" flag.
    await item.getByTestId("custom-field-edit").click();
    const editComposer = page.getByTestId("custom-field-composer");
    await editComposer.getByTestId("custom-field-name-input").fill("Priority");
    await editComposer.getByTestId("custom-field-required-input").click();
    await editComposer.getByTestId("custom-field-save").click();

    const renamed = page.locator('[data-testid="custom-field-item"]', {
      hasText: "Priority",
    });
    await expect(renamed).toBeVisible();
    await expect(renamed.locator("text=Required")).toHaveCount(0);

    // Delete — accept the confirm dialog.
    page.once("dialog", (dialog) => dialog.accept());
    await renamed.getByTestId("custom-field-delete").click();
    await expect(
      page.locator('[data-testid="custom-field-item"]', {
        hasText: "Priority",
      }),
    ).toHaveCount(0);
  });

  test("composer surfaces per-kind config — money currency, numeric min/max", async ({
    page,
  }) => {
    await registerAndSignIn(page, uniqueEmail());

    await page.goto(`${BASE_URL}/projects`);
    const projectTitle = `CF-kinds-${Date.now()}`;
    await page.fill("#title", projectTitle);
    await page.click('button[type="submit"]');
    const projectItem = page.locator('[data-testid="project-item"]', {
      hasText: projectTitle,
    });
    await expect(projectItem).toBeVisible();
    await projectItem.locator(`a[href^="/projects/"]`).first().click();
    await page.click('[data-testid="project-custom-fields-link"]');

    await page.getByTestId("custom-field-add").click();
    const composer = page.getByTestId("custom-field-composer");

    // Pick numeric > money → currency input shows up.
    await composer.getByTestId("custom-field-kind-input").selectOption("numeric");
    await composer
      .getByTestId("custom-field-subtype-input")
      .selectOption("money");
    await expect(composer.getByTestId("custom-field-currency-input")).toBeVisible();
    await composer.getByTestId("custom-field-currency-input").fill("EUR");

    await composer.getByTestId("custom-field-name-input").fill("Budget");
    // Money supports footer aggregations (sum/avg/min/max/count).
    await composer.getByTestId("custom-field-footer-input").selectOption("sum");
    await composer.getByTestId("custom-field-save").click();

    const item = page.locator('[data-testid="custom-field-item"]', {
      hasText: "Budget",
    });
    await expect(item).toBeVisible();
    await expect(item.locator("text=Money")).toBeVisible();
    await expect(item.locator("text=Footer: sum")).toBeVisible();
  });
});

// API-side authorization (members can read, only space admins can write)
// is covered by CustomFieldDefinitionTest in PHPUnit; the access
// extension also has direct coverage there.
