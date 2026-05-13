// @ts-check
const { test, expect } = require("@playwright/test");
const {
  BASE_URL,
  uniqueEmail: shared,
  registerAndSignIn,
} = require("./helpers");

const uniqueEmail = () => shared("space-attach");

const openFilesTab = async (page, spaceId) => {
  await page.goto(`${BASE_URL}/spaces/${spaceId}?tab=files`);
  const panel = page.locator(
    '[data-testid="space-attachments"] [data-testid="attachments-panel"]',
  );
  await expect(panel).toBeVisible();
  return panel;
};

const fetchPersonalSpace = async (page) => {
  const res = await page.request.get(`${BASE_URL}/spaces`, {
    headers: { Accept: "application/ld+json" },
  });
  expect(res.ok()).toBeTruthy();
  const data = await res.json();
  const list = data.member ?? data["hydra:member"] ?? [];
  const personal = list.find((s) => s.isPersonal);
  expect(personal).toBeDefined();
  return personal;
};

test.describe("Space attachments", () => {
  test("space admin can attach a file, see it listed, and remove it", async ({ page }) => {
    await registerAndSignIn(page, uniqueEmail());
    const space = await fetchPersonalSpace(page);

    const panel = await openFilesTab(page, space.id);
    await expect(panel.locator('[data-testid="attachment-item"]')).toHaveCount(0);

    await panel.locator('[data-testid="attachment-file-input"]').setInputFiles({
      name: "spec.txt",
      mimeType: "text/plain",
      buffer: Buffer.from("Space spec.\n"),
    });

    const attachment = panel.locator('[data-testid="attachment-item"]');
    await expect(attachment).toHaveCount(1);
    await expect(attachment).toContainText("spec.txt");

    const link = attachment.locator('[data-testid="attachment-link"]');
    expect(await link.getAttribute("href")).toMatch(
      /^\/media-objects\/[0-9a-f-]+\/download$/,
    );

    await attachment.locator('[data-testid="attachment-delete"]').click();
    await expect(panel.locator('[data-testid="attachment-item"]')).toHaveCount(0);
  });

  test("attachments survive a reload", async ({ page }) => {
    await registerAndSignIn(page, uniqueEmail());
    const space = await fetchPersonalSpace(page);

    const panel = await openFilesTab(page, space.id);
    await panel.locator('[data-testid="attachment-file-input"]').setInputFiles({
      name: "persist.txt",
      mimeType: "text/plain",
      buffer: Buffer.from("Survives reload."),
    });
    await expect(panel.locator('[data-testid="attachment-item"]')).toHaveCount(1);

    await page.reload();
    const reopened = page.locator(
      '[data-testid="space-attachments"] [data-testid="attachments-panel"]',
    );
    await expect(reopened.locator('[data-testid="attachment-item"]')).toContainText(
      "persist.txt",
    );
  });
});
