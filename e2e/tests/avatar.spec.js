// @ts-check
const { test, expect } = require("@playwright/test");
const { BASE_URL, uniqueEmail, registerAndSignIn } = require("./helpers");

// Minimal 1x1 red PNG. The backend's Intervention Image will upscale this
// to 64/256 px for the avatar variants; we just need a valid image payload.
const RED_PIXEL_PNG_BASE64 =
  "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==";
const AVATAR_FIXTURE = {
  name: "avatar.png",
  mimeType: "image/png",
  buffer: Buffer.from(RED_PIXEL_PNG_BASE64, "base64"),
};

test.describe("Avatar", () => {
  test("initials fallback renders when no avatar is set", async ({ page }) => {
    const email = uniqueEmail("avatar-fallback");
    await registerAndSignIn(page, email, "password123", {
      givenName: "Pat",
      familyName: "Quinn",
    });

    const avatar = page.locator('a[aria-label="My Account"] span').first();
    await expect(avatar).toBeVisible();
    await expect(avatar).toHaveText("PQ");
  });

  test("uploaded avatar renders as an image after upload", async ({ page }) => {
    const email = uniqueEmail("avatar-upload");
    await registerAndSignIn(page, email, "password123", {
      givenName: "Pat",
      familyName: "Quinn",
    });

    await page.goto(`${BASE_URL}/account`);
    await page.locator('input[type="file"]').setInputFiles(AVATAR_FIXTURE);

    const avatarImg = page.locator('a[aria-label="My Account"] img');
    await expect(avatarImg).toBeVisible({ timeout: 10000 });
    await expect(avatarImg).toHaveAttribute("src", /\/media\/avatars\/.+\.webp/);
  });
});
