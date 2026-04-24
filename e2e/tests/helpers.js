// @ts-check
const { expect } = require("@playwright/test");

const BASE_URL = "https://localhost";

const uniqueEmail = (prefix = "e2e") =>
  `${prefix}-${Date.now()}-${Math.random().toString(36).slice(2, 8)}@example.com`;

async function registerAndSignIn(page, email, password = "password123", options = {}) {
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
  await expect(page).toHaveURL(/\/account/);
}

/**
 * Type into the BlockNote description editor. The editor is a contenteditable
 * ProseMirror instance wrapped in our MarkdownEditor component, which exposes
 * `aria-label="Description"` (or an `id` when one is provided). Playwright's
 * `.fill()` is brittle on block editors, so we click into the editable and
 * use keyboard.type.
 *
 * @param {import('@playwright/test').Page} page
 * @param {import('@playwright/test').Locator | undefined} scope optional scope (e.g. a specific edit form)
 * @param {string} text
 */
async function fillDescription(page, scope, text) {
  const root = scope ?? page;
  const editor = root
    .locator('[aria-label="Description"] [contenteditable="true"]')
    .first();
  await editor.click();
  // Clear any existing content (edit mode pre-populates the block editor).
  await page.keyboard.press("ControlOrMeta+a");
  await page.keyboard.press("Delete");
  await page.keyboard.type(text);
}

module.exports = {
  BASE_URL,
  uniqueEmail,
  registerAndSignIn,
  fillDescription,
};
