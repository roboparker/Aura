// @ts-check
const { test, expect } = require("@playwright/test");
const {
  BASE_URL,
  uniqueEmail: shared,
  registerAndSignIn,
  createTaskInline,
} = require("./helpers");

const uniqueEmail = () => shared("attachments");

test.describe("Task attachments", () => {
  test("user can attach a file via the picker, see it listed, and remove it", async ({
    page,
  }) => {
    await registerAndSignIn(page, uniqueEmail());
    await page.goto(`${BASE_URL}/tasks`);

    const title = `Spec review ${Date.now()}`;
    await createTaskInline(page, title);

    const item = page.locator('[data-testid="task-item"]', { hasText: title });
    await expect(item).toBeVisible();

    // Open the attachments panel.
    await item.locator('[data-testid="task-attachments-toggle"]').click();
    const panel = item.locator('[data-testid="attachments-panel"]');
    await expect(panel).toBeVisible();
    await expect(panel.locator('[data-testid="attachment-item"]')).toHaveCount(0);

    // Pick a file via the hidden file input (the visible "choose from your
    // computer" button just clicks it; setInputFiles is the deterministic
    // path in tests).
    await item.locator('[data-testid="attachment-file-input"]').setInputFiles({
      name: "notes.txt",
      mimeType: "text/plain",
      buffer: Buffer.from("Initial spec notes.\n"),
    });

    // The list shows the new attachment with its name + size.
    const attachment = panel.locator('[data-testid="attachment-item"]');
    await expect(attachment).toHaveCount(1);
    await expect(attachment).toContainText("notes.txt");
    await expect(
      item.locator('[data-testid="task-attachments-toggle"]'),
    ).toContainText("Attachments (1)");

    // Download link points at the served media path.
    const link = attachment.locator('[data-testid="attachment-link"]');
    const href = await link.getAttribute("href");
    expect(href).toMatch(/^\/media\/attachments\//);

    // Remove the attachment.
    await attachment.locator('[data-testid="attachment-delete"]').click();
    await expect(panel.locator('[data-testid="attachment-item"]')).toHaveCount(0);
    await expect(
      item.locator('[data-testid="task-attachments-toggle"]'),
    ).toContainText("Attach");
  });

  test("rejects an oversized file before contacting the API", async ({ page }) => {
    await registerAndSignIn(page, uniqueEmail());
    await page.goto(`${BASE_URL}/tasks`);

    const title = `Big ${Date.now()}`;
    await createTaskInline(page, title);
    const item = page.locator('[data-testid="task-item"]', { hasText: title });

    await item.locator('[data-testid="task-attachments-toggle"]').click();
    const panel = item.locator('[data-testid="attachments-panel"]');

    // 11 MB plain text — over the 10 MB client-side cap.
    await item.locator('[data-testid="attachment-file-input"]').setInputFiles({
      name: "big.txt",
      mimeType: "text/plain",
      buffer: Buffer.alloc(11 * 1024 * 1024, "x"),
    });

    await expect(panel.locator('[data-testid="attachments-error"]')).toContainText(
      /larger than 10 MB/i,
    );
    await expect(panel.locator('[data-testid="attachment-item"]')).toHaveCount(0);
  });

  test("attachments survive a reload", async ({ page }) => {
    await registerAndSignIn(page, uniqueEmail());
    await page.goto(`${BASE_URL}/tasks`);

    const title = `Persist ${Date.now()}`;
    await createTaskInline(page, title);
    const item = page.locator('[data-testid="task-item"]', { hasText: title });

    await item.locator('[data-testid="task-attachments-toggle"]').click();
    await item.locator('[data-testid="attachment-file-input"]').setInputFiles({
      name: "persist.txt",
      mimeType: "text/plain",
      buffer: Buffer.from("Survives reload."),
    });
    await expect(
      item.locator('[data-testid="task-attachments-toggle"]'),
    ).toContainText("Attachments (1)");

    await page.reload();
    const reopened = page.locator('[data-testid="task-item"]', {
      hasText: title,
    });
    // Count is reflected on the toggle without expanding (server returned
    // the attachment inline on the task payload).
    await expect(
      reopened.locator('[data-testid="task-attachments-toggle"]'),
    ).toContainText("Attachments (1)");
    await reopened.locator('[data-testid="task-attachments-toggle"]').click();
    await expect(
      reopened.locator('[data-testid="attachment-item"]'),
    ).toContainText("persist.txt");
  });
});
