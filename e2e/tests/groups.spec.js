// @ts-check
const { test, expect } = require("@playwright/test");
const {
  BASE_URL,
  uniqueEmail: shared,
  registerAndSignIn,
  fillDescription,
  openAccountMenu,
} = require("./helpers");

const uniqueEmail = () => shared("groups");

test.describe("Groups", () => {
  test("unauthenticated visitors are redirected to sign in", async ({ page }) => {
    await page.goto(`${BASE_URL}/groups`);
    await expect(page).toHaveURL(/\/signin/);
  });

  test("user can create a group, add a member, and delete it", async ({ browser }) => {
    const ownerEmail = uniqueEmail();
    const memberEmail = uniqueEmail();
    const suffix = Date.now();
    const title = `Backend team ${suffix}`;

    // Member is created up-front so the owner can add them by email.
    const memberContext = await browser.newContext({ ignoreHTTPSErrors: true });
    const memberPage = await memberContext.newPage();
    await registerAndSignIn(memberPage, memberEmail);
    await memberContext.close();

    const ownerContext = await browser.newContext({ ignoreHTTPSErrors: true });
    const ownerPage = await ownerContext.newPage();
    await registerAndSignIn(ownerPage, ownerEmail);

    await ownerPage.goto(`${BASE_URL}/groups`);
    await expect(ownerPage).toHaveTitle("Groups - Aura");
    await expect(ownerPage.locator("text=No groups yet")).toBeVisible();

    // Create
    await ownerPage.fill("#title", title);
    await fillDescription(ownerPage, undefined, "PHP folks");
    await ownerPage.locator('button[type="submit"]', { hasText: /Add Group/ }).click();

    const item = ownerPage.locator('[data-testid="group-item"]', { hasText: title });
    await expect(item).toBeVisible();
    await expect(item.locator("text=PHP folks")).toBeVisible();
    // Creator is auto-added as a member.
    await expect(item.locator('[data-testid="group-member"]')).toContainText(ownerEmail);

    // Open detail page and add a member.
    await item.locator(`a:has-text("${title}")`).click();
    await expect(ownerPage).toHaveURL(/\/groups\/[\w-]+/);
    await expect(ownerPage.locator('[data-testid="group-owner"]')).toContainText(ownerEmail);

    await ownerPage.locator('[data-testid="add-member-form"] input').fill(memberEmail);
    await ownerPage.locator('[data-testid="add-member-form"] button[type="submit"]').click();
    await expect(ownerPage.locator('[data-testid="member-pill"]', { hasText: memberEmail })).toBeVisible();

    // Delete from the list page.
    await ownerPage.goto(`${BASE_URL}/groups`);
    const listItem = ownerPage.locator('[data-testid="group-item"]', { hasText: title });
    ownerPage.once("dialog", (dialog) => dialog.accept());
    await listItem.getByRole("button", { name: /Delete "/ }).click();
    await expect(ownerPage.locator("text=No groups yet")).toBeVisible();

    await ownerContext.close();
  });

  test("non-owner members see read-only detail view", async ({ browser }) => {
    const ownerEmail = uniqueEmail();
    const memberEmail = uniqueEmail();
    const suffix = Date.now();
    const title = `Read-only ${suffix}`;

    // Pre-create the member account so the owner can add them.
    const memberContext = await browser.newContext({ ignoreHTTPSErrors: true });
    const memberPage = await memberContext.newPage();
    await registerAndSignIn(memberPage, memberEmail);

    const ownerContext = await browser.newContext({ ignoreHTTPSErrors: true });
    const ownerPage = await ownerContext.newPage();
    await registerAndSignIn(ownerPage, ownerEmail);
    await ownerPage.goto(`${BASE_URL}/groups`);
    await ownerPage.fill("#title", title);
    await ownerPage.locator('button[type="submit"]', { hasText: /Add Group/ }).click();
    const ownerItem = ownerPage.locator('[data-testid="group-item"]', { hasText: title });
    await ownerItem.locator(`a:has-text("${title}")`).click();
    await ownerPage.locator('[data-testid="add-member-form"] input').fill(memberEmail);
    await ownerPage.locator('[data-testid="add-member-form"] button[type="submit"]').click();
    await expect(ownerPage.locator('[data-testid="member-pill"]', { hasText: memberEmail })).toBeVisible();
    await ownerContext.close();

    // Now the member visits — they should see the group but no editing UI.
    await memberPage.goto(`${BASE_URL}/groups`);
    const memberItem = memberPage.locator('[data-testid="group-item"]', { hasText: title });
    await expect(memberItem).toBeVisible();
    // No Delete button on the list for non-owners.
    await expect(memberItem.getByRole("button", { name: /Delete "/ })).toHaveCount(0);

    await memberItem.locator(`a:has-text("${title}")`).click();
    // No edit/delete/transfer/add-member affordances on the detail page either.
    await expect(memberPage.getByRole("button", { name: /^Edit$/ })).toHaveCount(0);
    await expect(memberPage.getByRole("button", { name: /^Delete$/ })).toHaveCount(0);
    await expect(memberPage.locator('[data-testid="add-member-form"]')).toHaveCount(0);
    await expect(memberPage.locator('[data-testid="transfer-ownership-form"]')).toHaveCount(0);

    await memberContext.close();
  });

  test("owner can invite members during group creation", async ({ browser }) => {
    const ownerEmail = uniqueEmail();
    const memberEmail = uniqueEmail();
    const suffix = Date.now();
    const title = `Invite-on-create ${suffix}`;

    // Member must exist so the email lookup resolves.
    const memberContext = await browser.newContext({ ignoreHTTPSErrors: true });
    const memberPage = await memberContext.newPage();
    await registerAndSignIn(memberPage, memberEmail);
    await memberContext.close();

    const ownerContext = await browser.newContext({ ignoreHTTPSErrors: true });
    const ownerPage = await ownerContext.newPage();
    await registerAndSignIn(ownerPage, ownerEmail);
    await ownerPage.goto(`${BASE_URL}/groups`);

    await ownerPage.fill("#title", title);

    // Queue an invite before submitting the form.
    await ownerPage.fill("#invite-email", memberEmail);
    await ownerPage.locator('[data-testid="invite-members-section"] button', { hasText: /^Add$/ }).click();
    await expect(
      ownerPage.locator('[data-testid="pending-invite"]', { hasText: memberEmail }),
    ).toBeVisible();

    await ownerPage.locator('button[type="submit"]', { hasText: /Add Group/ }).click();

    const item = ownerPage.locator('[data-testid="group-item"]', { hasText: title });
    await expect(item).toBeVisible();
    // The invitee should already appear as a member without a second step.
    await expect(item.locator('[data-testid="group-member"]', { hasText: memberEmail })).toBeVisible();

    await ownerContext.close();
  });

  test("inviting an unknown email creates the group with a pending invite", async ({ page }) => {
    await registerAndSignIn(page, uniqueEmail());
    await page.goto(`${BASE_URL}/groups`);

    const ghost = `ghost-${Date.now()}@example.invalid`;
    const title = `Pending invite ${Date.now()}`;
    await page.fill("#title", title);
    await page.fill("#invite-email", ghost);
    await page.locator('[data-testid="invite-members-section"] button', { hasText: /^Add$/ }).click();
    await page.locator('button[type="submit"]', { hasText: /Add Group/ }).click();

    const item = page.locator('[data-testid="group-item"]', { hasText: title });
    await expect(item).toBeVisible();

    // The unknown email is not a member (no Aura account yet) but the
    // invite is recorded — visit the detail page to see the pending chip.
    await item.locator(`a:has-text("${title}")`).click();
    await expect(
      page.locator('[data-testid="pending-invite-pill"]', { hasText: ghost }),
    ).toBeVisible();
  });

  test("account menu shows Groups link when authenticated", async ({ page }) => {
    await registerAndSignIn(page, uniqueEmail());
    await openAccountMenu(page);
    await expect(page.locator("nav >> text=Groups")).toBeVisible();
    await page.locator("nav >> text=Groups").click();
    await expect(page).toHaveURL(/\/groups/);
  });
});
