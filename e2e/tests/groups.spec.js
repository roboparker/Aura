// @ts-check
const { test, expect } = require("@playwright/test");
const {
  BASE_URL,
  uniqueEmail: shared,
  registerAndSignIn,
  openAccountMenu,
} = require("./helpers");

const uniqueEmail = () => shared("groups");
const PASSWORD = "Password123!@#";

// Create a group through the full-page composer at /groups/new. Optionally
// seeds invite chips (existing users join immediately; unknown emails get a
// pending invite). Lands on the new group's detail page.
async function createGroup(page, title, { invites = [] } = {}) {
  await page.goto(`${BASE_URL}/groups/new`);
  await page.fill("#group-title", title);
  for (const email of invites) {
    await page.fill("#group-invites", email);
    await page.press("#group-invites", "Enter");
  }
  await page.locator('button[type="submit"]', { hasText: /Create group/ }).click();
  await expect(page).toHaveURL(/\/groups\/[\w-]+/);
  await expect(page.getByRole("heading", { name: title })).toBeVisible();
}

test.describe("Groups", () => {
  test("unauthenticated visitors are redirected to sign in", async ({ page }) => {
    await page.goto(`${BASE_URL}/groups`);
    await expect(page).toHaveURL(/\/signin/);
  });

  test("owner can create a group, add a member, and delete it", async ({ browser }) => {
    const ownerEmail = uniqueEmail();
    const memberEmail = uniqueEmail();
    const title = `Backend team ${Date.now()}`;

    // Member is created up-front so the owner can add them by email.
    const memberContext = await browser.newContext({ ignoreHTTPSErrors: true });
    const memberPage = await memberContext.newPage();
    await registerAndSignIn(memberPage, memberEmail);
    await memberContext.close();

    const ownerContext = await browser.newContext({ ignoreHTTPSErrors: true });
    const ownerPage = await ownerContext.newPage();
    await registerAndSignIn(ownerPage, ownerEmail);

    await ownerPage.goto(`${BASE_URL}/groups`);
    await expect(ownerPage).toHaveTitle("Groups - Madori");
    await expect(ownerPage.locator("text=No groups yet")).toBeVisible();

    // Create via the empty-state call to action → /groups/new.
    await ownerPage.getByRole("link", { name: /Create your first group/ }).click();
    await expect(ownerPage).toHaveURL(/\/groups\/new/);
    await ownerPage.fill("#group-title", title);
    await ownerPage.locator('button[type="submit"]', { hasText: /Create group/ }).click();

    // Lands on the detail page as owner.
    await expect(ownerPage).toHaveURL(/\/groups\/[\w-]+/);
    await expect(ownerPage.getByRole("heading", { name: title })).toBeVisible();
    await expect(ownerPage.locator('[data-testid="member-row"]')).toContainText(ownerEmail);

    // Add a member by email.
    await ownerPage.locator('[data-testid="add-member-form"] input').fill(memberEmail);
    await ownerPage
      .locator('[data-testid="add-member-form"] button[type="submit"]')
      .click();
    await expect(
      ownerPage.locator('[data-testid="member-row"]', { hasText: memberEmail }),
    ).toBeVisible();

    // It shows up on the index.
    await ownerPage.goto(`${BASE_URL}/groups`);
    await expect(
      ownerPage.locator('[data-testid="group-item"]', { hasText: title }),
    ).toBeVisible();

    // Delete from the detail page — step-up dialog requires the name + password.
    await ownerPage
      .locator('[data-testid="group-item"]', { hasText: title })
      .getByRole("link", { name: title })
      .click();
    await ownerPage.getByRole("button", { name: /^Delete$/ }).click();
    const dialog = ownerPage.getByRole("dialog");
    await expect(dialog.getByText("Delete this group?")).toBeVisible();
    await dialog.locator("#delete-group-name").fill(title);
    await dialog.locator("#delete-group-credential").fill(PASSWORD);
    await dialog.getByRole("button", { name: /Delete group/ }).click();

    await expect(ownerPage).toHaveURL(/\/groups$/);
    await expect(ownerPage.locator("text=No groups yet")).toBeVisible();

    await ownerContext.close();
  });

  test("non-owner members see a read-only detail view", async ({ browser }) => {
    const ownerEmail = uniqueEmail();
    const memberEmail = uniqueEmail();
    const title = `Read-only ${Date.now()}`;

    const memberContext = await browser.newContext({ ignoreHTTPSErrors: true });
    const memberPage = await memberContext.newPage();
    await registerAndSignIn(memberPage, memberEmail);

    const ownerContext = await browser.newContext({ ignoreHTTPSErrors: true });
    const ownerPage = await ownerContext.newPage();
    await registerAndSignIn(ownerPage, ownerEmail);
    // Seed the member via invite-on-create (existing user → immediate member).
    await createGroup(ownerPage, title, { invites: [memberEmail] });
    await expect(
      ownerPage.locator('[data-testid="member-row"]', { hasText: memberEmail }),
    ).toBeVisible();
    await ownerContext.close();

    // The member sees the group but none of the management affordances.
    await memberPage.goto(`${BASE_URL}/groups`);
    const memberItem = memberPage.locator('[data-testid="group-item"]', { hasText: title });
    await expect(memberItem).toBeVisible();

    await memberItem.getByRole("link", { name: title }).click();
    await expect(memberPage.getByRole("heading", { name: title })).toBeVisible();
    await expect(memberPage.getByRole("button", { name: /^Edit$/ })).toHaveCount(0);
    await expect(memberPage.getByRole("button", { name: /^Delete$/ })).toHaveCount(0);
    await expect(memberPage.locator('[data-testid="add-member-form"]')).toHaveCount(0);
    // Members get a Leave control instead of the danger zone.
    await expect(memberPage.getByRole("button", { name: /Leave group/ })).toBeVisible();

    await memberContext.close();
  });

  test("owner can invite an existing user during group creation", async ({ browser }) => {
    const ownerEmail = uniqueEmail();
    const memberEmail = uniqueEmail();
    const title = `Invite-on-create ${Date.now()}`;

    const memberContext = await browser.newContext({ ignoreHTTPSErrors: true });
    const memberPage = await memberContext.newPage();
    await registerAndSignIn(memberPage, memberEmail);
    await memberContext.close();

    const ownerContext = await browser.newContext({ ignoreHTTPSErrors: true });
    const ownerPage = await ownerContext.newPage();
    await registerAndSignIn(ownerPage, ownerEmail);

    await createGroup(ownerPage, title, { invites: [memberEmail] });
    // The invitee already appears as a member without a second step.
    await expect(
      ownerPage.locator('[data-testid="member-row"]', { hasText: memberEmail }),
    ).toBeVisible();

    await ownerContext.close();
  });

  test("inviting an unknown email records a pending invite", async ({ page }) => {
    await registerAndSignIn(page, uniqueEmail());

    const ghost = `ghost-${Date.now()}@example.invalid`;
    const title = `Pending invite ${Date.now()}`;
    await createGroup(page, title, { invites: [ghost] });

    // The unknown email isn't a member yet, but the pending invite shows on
    // the owner's detail view.
    await expect(
      page.locator('[data-testid="pending-invite-row"]', { hasText: ghost }),
    ).toBeVisible();
  });

  test("account menu shows Manage Groups link when authenticated", async ({
    page,
  }) => {
    await registerAndSignIn(page, uniqueEmail());
    await openAccountMenu(page);
    const groupsItem = page.getByRole("menuitem", { name: "Manage Groups" });
    await expect(groupsItem).toBeVisible();
    await groupsItem.click();
    await expect(page).toHaveURL(/\/groups/);
  });
});
