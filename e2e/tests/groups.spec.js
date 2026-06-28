// @ts-check
const { test, expect } = require("@playwright/test");
const {
  BASE_URL,
  uniqueEmail: shared,
  registerAndSignIn,
} = require("./helpers");

const uniqueEmail = () => shared("groups");

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

    // Create via the full-page composer (the standalone index was folded into
    // the space Users page).
    await createGroup(ownerPage, title);
    await expect(ownerPage.locator('[data-testid="member-row"]')).toContainText(ownerEmail);

    // Add a member by email.
    await ownerPage.locator('[data-testid="add-member-form"] input').fill(memberEmail);
    await ownerPage
      .locator('[data-testid="add-member-form"] button[type="submit"]')
      .click();
    await expect(
      ownerPage.locator('[data-testid="member-row"]', { hasText: memberEmail }),
    ).toBeVisible();

    // Delete from the detail page — type-to-confirm dialog (no step-up now;
    // any space member can delete a group).
    await ownerPage.getByRole("button", { name: /^Delete$/ }).click();
    const dialog = ownerPage.getByRole("dialog");
    await expect(dialog.getByText("Delete this group?")).toBeVisible();
    await dialog.locator("#delete-group-name").fill(title);
    await dialog.getByRole("button", { name: /Delete group/ }).click();

    // Deleting drops us on the space Users page; the group is gone.
    await expect(ownerPage).toHaveURL(/\/spaces\/[\w-]+\/users/);
    await expect(ownerPage.locator(`text=${title}`)).toHaveCount(0);

    await ownerContext.close();
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
});
