// @ts-check
const { test, expect } = require("@playwright/test");

const BASE_URL = "https://localhost";
const MAILPIT_URL = process.env.MAILPIT_URL || "http://localhost:8025";

const uniqueEmail = (prefix = "email-change") =>
  `${prefix}-${Date.now()}-${Math.floor(Math.random() * 10000)}@example.com`;

async function registerAndSignIn(page, request, email, password = "password123") {
  const res = await request.post(`${BASE_URL}/users`, {
    headers: { "Content-Type": "application/ld+json" },
    data: { email, plainPassword: password, givenName: "E2e", familyName: "User" },
  });
  expect(res.ok()).toBeTruthy();

  await page.goto(`${BASE_URL}/signin`);
  await page.fill("#email", email);
  await page.fill("#password", password);
  await page.click('button[type="submit"]');
  await expect(page).toHaveURL(/\/account/);
}

async function getLatestEmail(request, recipient, timeout = 5000) {
  const deadline = Date.now() + timeout;
  while (Date.now() < deadline) {
    const res = await request.get(`${MAILPIT_URL}/api/v1/messages?limit=50`);
    if (res.ok()) {
      const { messages = [] } = await res.json();
      const match = messages.find((m) =>
        (m.To || []).some((to) => to.Address === recipient),
      );
      if (match) {
        const detailRes = await request.get(
          `${MAILPIT_URL}/api/v1/message/${match.ID}`,
        );
        if (detailRes.ok()) return detailRes.json();
      }
    }
    await new Promise((resolve) => setTimeout(resolve, 250));
  }
  throw new Error(`No email to ${recipient} found within ${timeout}ms`);
}

async function clearMailpit(request) {
  await request.delete(`${MAILPIT_URL}/api/v1/messages`);
}

test.describe("Change email (authenticated)", () => {
  test("full flow: request → confirm → revert", async ({ page, request }) => {
    const oldEmail = uniqueEmail("old");
    const newEmail = uniqueEmail("new");
    await registerAndSignIn(page, request, oldEmail);
    await clearMailpit(request);

    // Submit new email
    await page.locator('input[name="newEmail"]').fill(newEmail);
    await page.click('button:has-text("Send confirmation link")');
    await expect(page.locator(`text=${newEmail}`).first()).toBeVisible();

    // Pick the confirm link out of the email sent to the new address
    const confirmMessage = await getLatestEmail(request, newEmail);
    const confirmBody = confirmMessage.Text || confirmMessage.HTML || "";
    const confirmMatch = confirmBody.match(
      /confirm-email-change\?token=([a-f0-9]+)/,
    );
    expect(
      confirmMatch,
      "Confirmation email should contain a confirm link",
    ).toBeTruthy();
    const confirmUrl = `${BASE_URL}/confirm-email-change?token=${confirmMatch[1]}`;

    await clearMailpit(request);
    await page.goto(confirmUrl);
    await expect(page.locator(`text=${newEmail}`).first()).toBeVisible();

    // The notice email goes to the *old* address with the revert link
    const noticeMessage = await getLatestEmail(request, oldEmail);
    const noticeBody = noticeMessage.Text || noticeMessage.HTML || "";
    const revertMatch = noticeBody.match(
      /revert-email-change\?token=([a-f0-9]+)/,
    );
    expect(revertMatch, "Notice email should contain a revert link").toBeTruthy();
    expect(noticeBody).toMatch(/forgot-password/);
    const revertUrl = `${BASE_URL}/revert-email-change?token=${revertMatch[1]}`;

    // Sign back in with the new email so /confirm and /revert have a session
    // refresh path that mirrors a real user — not strictly required by the
    // API (both endpoints are public), but it exercises refreshUser().
    await page.goto(`${BASE_URL}/signin`);
    await page.fill("#email", newEmail);
    await page.fill("#password", "password123");
    await page.click('button[type="submit"]');
    await expect(page).toHaveURL(/\/account/);

    // Now follow the revert link
    await page.goto(revertUrl);
    await expect(page.locator(`text=${oldEmail}`).first()).toBeVisible();

    // The old email should once again be the working credential
    await page.goto(`${BASE_URL}/signin`);
    await page.fill("#email", oldEmail);
    await page.fill("#password", "password123");
    await page.click('button[type="submit"]');
    await expect(page).toHaveURL(/\/account/);
  });

  test("cannot request a change to an already-registered address", async ({
    page,
    request,
  }) => {
    const a = uniqueEmail("taken-a");
    const b = uniqueEmail("taken-b");
    await registerAndSignIn(page, request, a);
    // Register a second user via the API so `b` is taken
    await request.post(`${BASE_URL}/users`, {
      headers: { "Content-Type": "application/ld+json" },
      data: { email: b, plainPassword: "password123", givenName: "Other", familyName: "User" },
    });

    await clearMailpit(request);

    await page.locator('input[name="newEmail"]').fill(b);
    await page.click('button:has-text("Send confirmation link")');

    // The form still shows a generic "we sent a link" success message —
    // the API silently no-ops to avoid leaking address registration.
    await expect(page.locator(`text=${b}`).first()).toBeVisible();

    // ...but no email actually gets delivered.
    await new Promise((resolve) => setTimeout(resolve, 500));
    const res = await request.get(`${MAILPIT_URL}/api/v1/messages?limit=50`);
    const { messages = [] } = await res.json();
    expect(
      messages.some((m) => (m.To || []).some((to) => to.Address === b)),
      "No confirmation email should be delivered for a taken address",
    ).toBeFalsy();
  });
});
