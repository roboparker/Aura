// @ts-check
const { test, expect } = require("@playwright/test");
const {
  BASE_URL,
  uniqueEmail: shared,
  registerAndSignIn,
} = require("./helpers");

const uniqueEmail = () => shared("project-attach");

test.describe("Project attachments", () => {
  test("project owner can attach a file, see it listed, and remove it", async ({ page }) => {
    await registerAndSignIn(page, uniqueEmail());

    // Create a project via the API for a deterministic detail URL — the
    // creation flow itself is covered by projects.spec.js.
    const created = await page.request.post(`${BASE_URL}/projects`, {
      headers: { "Content-Type": "application/ld+json" },
      data: { title: `Attach project ${Date.now()}`, description: null },
    });
    expect(created.ok()).toBeTruthy();
    const project = await created.json();

    await page.goto(`${BASE_URL}/projects/${project.id}`);
    const panel = page.locator('[data-testid="project-attachments"] [data-testid="attachments-panel"]');
    await expect(panel).toBeVisible();
    await expect(panel.locator('[data-testid="attachment-item"]')).toHaveCount(0);

    // Pick a file via the hidden file input.
    await panel.locator('[data-testid="attachment-file-input"]').setInputFiles({
      name: "spec.txt",
      mimeType: "text/plain",
      buffer: Buffer.from("Project spec.\n"),
    });

    const attachment = panel.locator('[data-testid="attachment-item"]');
    await expect(attachment).toHaveCount(1);
    await expect(attachment).toContainText("spec.txt");

    // Project attachments use the same gated download URL as task ones.
    const link = attachment.locator('[data-testid="attachment-link"]');
    expect(await link.getAttribute("href")).toMatch(
      /^\/media-objects\/[0-9a-f-]+\/download$/,
    );

    // Owner has the delete button — remove and confirm.
    await attachment.locator('[data-testid="attachment-delete"]').click();
    await expect(panel.locator('[data-testid="attachment-item"]')).toHaveCount(0);
  });

  test("attachments survive a reload", async ({ page }) => {
    await registerAndSignIn(page, uniqueEmail());

    const created = await page.request.post(`${BASE_URL}/projects`, {
      headers: { "Content-Type": "application/ld+json" },
      data: { title: `Persist project ${Date.now()}` },
    });
    const project = await created.json();

    await page.goto(`${BASE_URL}/projects/${project.id}`);
    const panel = page.locator('[data-testid="project-attachments"] [data-testid="attachments-panel"]');
    await panel.locator('[data-testid="attachment-file-input"]').setInputFiles({
      name: "persist.txt",
      mimeType: "text/plain",
      buffer: Buffer.from("Survives reload."),
    });
    await expect(panel.locator('[data-testid="attachment-item"]')).toHaveCount(1);

    await page.reload();
    const reopened = page.locator('[data-testid="project-attachments"] [data-testid="attachments-panel"]');
    await expect(reopened.locator('[data-testid="attachment-item"]')).toContainText(
      "persist.txt",
    );
  });
});
