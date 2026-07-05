// @ts-check
const { expect } = require("@playwright/test");

// Honour E2E_BASE_URL so per-worktree compose stacks (which publish on
// non-default ports via scripts/worktree-env.sh) can run the suite without
// editing this file. CI leaves it unset and gets the default.
const BASE_URL = process.env.E2E_BASE_URL || "https://localhost";

const uniqueEmail = (prefix = "e2e") =>
  `${prefix}-${Date.now()}-${Math.random().toString(36).slice(2, 8)}@example.com`;

async function registerAndSignIn(page, email, password = "Password123!@#", options = {}) {
  const { givenName = "E2e", familyName = "User" } = options;
  const res = await page.request.post(`${BASE_URL}/users`, {
    headers: { "Content-Type": "application/ld+json" },
    data: { email, plainPassword: password, givenName, familyName },
  });
  expect(res.ok()).toBeTruthy();

  await page.goto(`${BASE_URL}/signin`);
  await page.fill("#email", email);
  await page.fill("#password", password);
  await page.click('button[type="submit"]');
  // A fresh sign-in with no `?next=` lands on the workspace home
  // (/boards), with the user's Private space active (#405).
  await expect(page).toHaveURL(/\/boards/);
}

/**
 * Type into a BlockNote editor — used for both task descriptions and task
 * comments. The editor is a contenteditable ProseMirror instance wrapped
 * in our MarkdownEditor component, which exposes an `aria-label` set by the
 * caller (e.g. "Description", `Description for "task title"`,
 * `Comment on "task title"`, "Edit comment"). Playwright's `.fill()` is
 * brittle on block editors, so we click into the editable and use
 * keyboard.type.
 *
 * @param {import('@playwright/test').Page} page
 * @param {import('@playwright/test').Locator | undefined} scope optional scope (e.g. a specific edit form)
 * @param {string} text
 * @param {{ ariaLabelPrefix?: string }} [options] aria-label prefix to disambiguate when multiple editors exist in scope; defaults to "Description"
 */
async function fillDescription(page, scope, text, options = {}) {
  const { ariaLabelPrefix = "Description" } = options;
  const root = scope ?? page;
  const editor = root
    .locator(`[aria-label^="${ariaLabelPrefix}"] [contenteditable="true"]`)
    .first();
  await editor.click();
  // Clear any existing content (edit mode pre-populates the block editor).
  await page.keyboard.press("ControlOrMeta+a");
  await page.keyboard.press("Delete");
  await page.keyboard.type(text);
}

/**
 * Create a task using the inline new-task row at the top of the Tasks
 * table. Pre-condition: the page is already at /tasks (or visible there).
 * Optionally stages a description by opening the inline editor first.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} title
 * @param {{ description?: string }} [options]
 */
async function createTaskInline(page, title, options = {}) {
  const input = page.locator('[data-testid="new-task-title-input"]');
  await input.fill(title);
  if (options.description) {
    const newRow = page.locator('[data-testid="new-task-row"]');
    await newRow.locator('[data-testid="new-task-description-add"]').click();
    await fillDescription(page, newRow, options.description);
    await newRow.locator('[data-testid="new-task-description-save"]').click();
  }
  // Locator.press focuses before pressing, which puts the cursor back in
  // the title input even if focus drifted into the description editor.
  await input.press("Enter");
  await expect(
    page.locator('[data-testid="task-item"]', { hasText: title }),
  ).toBeVisible();
}

/**
 * Open the user account menu in the sidebar header (#nav-refresh).
 * The personal links (My Tasks, Feedback, Settings) and Sign Out
 * now live in a dropdown opened by the avatar/chevron trigger rather
 * than as always-visible sidebar links. Callers can read menu items
 * (role="menuitem") once this resolves.
 *
 * @param {import('@playwright/test').Page} page
 */
async function openAccountMenu(page) {
  // Sanity-check that the sidebar (or its mobile equivalent) is
  // mounted, so a missed selector below points at a real bug rather
  // than a timing issue.
  await page.locator('[data-testid="app-sidebar"]').first().waitFor({
    state: "attached",
    timeout: 5000,
  });
  await page.locator('[data-testid="user-menu-trigger"]').first().click();
}

/**
 * Fixture Uma's TOTP secret. Mirrors
 * `App\DataFixtures\UserFixtures::FIXTURE_TOTP_SECRET` so tests can
 * compute a valid code without scraping it off the page. Same secret
 * across every fixture reload — re-pairing each test would just add
 * flakiness for no gain.
 */
const FIXTURE_TOTP_SECRET = "JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP";

/**
 * Sign in as Uma (the standard fixture user) through the full
 * password + TOTP challenge. Uma's fixture row has 2FA enabled with a
 * stable secret so we can compute the code in-process; failing that
 * would require either disabling 2FA for tests (defeats coverage) or
 * burning a one-shot backup code per run (mutates fixture state).
 *
 * Lands on `/settings/profile` once the challenge completes.
 *
 * @param {import('@playwright/test').Page} page
 */
async function signInAsFixtureUser(page) {
  await page.goto(`${BASE_URL}/signin`);
  await page.fill("#email", "user@madori.test");
  await page.fill("#password", "user123");
  await page.click('button[type="submit"]');

  await expect(page.getByText("Two-factor authentication")).toBeVisible();

  const code = await computeTotpCode(FIXTURE_TOTP_SECRET);
  const submitRes = await page.request.post(`${BASE_URL}/auth/2fa-check`, {
    headers: { "Content-Type": "application/json" },
    data: { code },
  });
  expect(submitRes.ok()).toBeTruthy();

  await page.goto(`${BASE_URL}/settings/profile`);
  await expect(page).toHaveURL(/\/settings\/profile/);
}

/**
 * Browser-side TOTP for tests. Mirrors the OTPHP defaults
 * (SHA1, 6-digit, 30-second period). Kept here so any spec that needs
 * to drive the 2FA challenge programmatically can reuse it without
 * pulling in a dedicated TOTP library.
 *
 * @param {string} base32Secret
 * @returns {Promise<string>}
 */
async function computeTotpCode(base32Secret) {
  const key = base32Decode(base32Secret);
  const counter = Math.floor(Date.now() / 1000 / 30);
  const counterBytes = new ArrayBuffer(8);
  new DataView(counterBytes).setBigUint64(0, BigInt(counter));
  const cryptoKey = await crypto.subtle.importKey(
    "raw",
    key,
    { name: "HMAC", hash: "SHA-1" },
    false,
    ["sign"],
  );
  const hmac = new Uint8Array(await crypto.subtle.sign("HMAC", cryptoKey, counterBytes));
  const offset = hmac[hmac.length - 1] & 0x0f;
  const truncated =
    ((hmac[offset] & 0x7f) << 24) |
    ((hmac[offset + 1] & 0xff) << 16) |
    ((hmac[offset + 2] & 0xff) << 8) |
    (hmac[offset + 3] & 0xff);
  return String(truncated % 1000000).padStart(6, "0");
}

/** Minimal RFC 4648 base32 decoder; uppercase, no padding handling beyond stripping. */
function base32Decode(input) {
  const alphabet = "ABCDEFGHIJKLMNOPQRSTUVWXYZ234567";
  const cleaned = input.replace(/=+$/, "").toUpperCase();
  let bits = 0;
  let value = 0;
  const output = [];
  for (const char of cleaned) {
    const idx = alphabet.indexOf(char);
    if (idx < 0) continue;
    value = (value << 5) | idx;
    bits += 5;
    if (bits >= 8) {
      bits -= 8;
      output.push((value >>> bits) & 0xff);
    }
  }
  return new Uint8Array(output);
}

module.exports = {
  BASE_URL,
  FIXTURE_TOTP_SECRET,
  uniqueEmail,
  registerAndSignIn,
  signInAsFixtureUser,
  computeTotpCode,
  fillDescription,
  createTaskInline,
  openAccountMenu,
};
