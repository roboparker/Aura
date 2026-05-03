// @ts-check
const { test, expect } = require("@playwright/test");
const {
  BASE_URL,
  uniqueEmail: shared,
  registerAndSignIn,
  createTaskInline,
} = require("./helpers");

const uniqueEmail = () => shared("attach-lightbox");

// Smallest valid PNGs (1x1) — server validates the upload by sniffing the
// MIME, so a real image header is required even though the bytes are tiny.
const tinyPng = (color) => {
  // Two pre-built 1x1 PNGs so two distinct attachments produce different
  // image objects (helps reasoning about the lightbox cycling between them).
  if (color === "red") {
    return Buffer.from(
      "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==",
      "base64",
    );
  }
  return Buffer.from(
    "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==",
    "base64",
  );
};

test.describe("Image attachment lightbox", () => {
  test("image attachments render a thumbnail and open in a lightbox", async ({
    page,
  }) => {
    await registerAndSignIn(page, uniqueEmail());
    await page.goto(`${BASE_URL}/tasks`);

    const title = `Lightbox ${Date.now()}`;
    await createTaskInline(page, title);
    const item = page.locator('[data-testid="task-item"]', { hasText: title });

    await item.locator('[data-testid="task-attachments-toggle"]').click();
    const panel = item.locator('[data-testid="attachments-panel"]');

    await item.locator('[data-testid="attachment-file-input"]').setInputFiles({
      name: "shot.png",
      mimeType: "image/png",
      buffer: tinyPng("red"),
    });

    const attachment = panel.locator('[data-testid="attachment-item"]');
    await expect(attachment).toHaveCount(1);
    // Image rows expose a thumbnail button and no <a> link.
    await expect(
      attachment.locator('[data-testid="attachment-thumbnail"]'),
    ).toBeVisible();

    // Click the thumbnail → lightbox opens.
    await attachment.locator('[data-testid="attachment-thumbnail"]').click();
    const lightbox = page.locator('[data-testid="attachment-lightbox"]');
    await expect(lightbox).toBeVisible();
    await expect(
      lightbox.locator('[data-testid="attachment-lightbox-image"]'),
    ).toHaveAttribute("alt", "shot.png");

    // Esc closes.
    await page.keyboard.press("Escape");
    await expect(lightbox).toBeHidden();
  });

  test("arrow keys cycle through image attachments on the same task", async ({
    page,
  }) => {
    await registerAndSignIn(page, uniqueEmail());
    await page.goto(`${BASE_URL}/tasks`);

    const title = `Lightbox cycle ${Date.now()}`;
    await createTaskInline(page, title);
    const item = page.locator('[data-testid="task-item"]', { hasText: title });

    await item.locator('[data-testid="task-attachments-toggle"]').click();
    const panel = item.locator('[data-testid="attachments-panel"]');
    const fileInput = item.locator('[data-testid="attachment-file-input"]');

    await fileInput.setInputFiles({
      name: "first.png",
      mimeType: "image/png",
      buffer: tinyPng("red"),
    });
    await expect(panel.locator('[data-testid="attachment-item"]')).toHaveCount(
      1,
    );
    await fileInput.setInputFiles({
      name: "second.png",
      mimeType: "image/png",
      buffer: tinyPng("blue"),
    });
    await expect(panel.locator('[data-testid="attachment-item"]')).toHaveCount(
      2,
    );

    // Open lightbox on the first image.
    await panel
      .locator('[data-testid="attachment-thumbnail"]')
      .first()
      .click();
    const lightboxImg = page.locator(
      '[data-testid="attachment-lightbox-image"]',
    );
    await expect(lightboxImg).toHaveAttribute("alt", "first.png");

    // Arrow-right cycles to the next image.
    await page.keyboard.press("ArrowRight");
    await expect(lightboxImg).toHaveAttribute("alt", "second.png");

    // Arrow-right wraps back to the first.
    await page.keyboard.press("ArrowRight");
    await expect(lightboxImg).toHaveAttribute("alt", "first.png");

    // Arrow-left wraps to the last.
    await page.keyboard.press("ArrowLeft");
    await expect(lightboxImg).toHaveAttribute("alt", "second.png");

    await page.keyboard.press("Escape");
    await expect(
      page.locator('[data-testid="attachment-lightbox"]'),
    ).toBeHidden();
  });

  test("non-image attachments still render with a download link", async ({
    page,
  }) => {
    await registerAndSignIn(page, uniqueEmail());
    await page.goto(`${BASE_URL}/tasks`);

    const title = `Mixed ${Date.now()}`;
    await createTaskInline(page, title);
    const item = page.locator('[data-testid="task-item"]', { hasText: title });

    await item.locator('[data-testid="task-attachments-toggle"]').click();
    await item.locator('[data-testid="attachment-file-input"]').setInputFiles({
      name: "notes.txt",
      mimeType: "text/plain",
      buffer: Buffer.from("Plaintext attachment.\n"),
    });
    const panel = item.locator('[data-testid="attachments-panel"]');
    const attachment = panel.locator('[data-testid="attachment-item"]').first();
    // Non-image rows have an <a href> link, no thumbnail button.
    await expect(
      attachment.locator('[data-testid="attachment-thumbnail"]'),
    ).toHaveCount(0);
    const link = attachment.locator('[data-testid="attachment-link"]');
    const tag = await link.evaluate((el) => el.tagName.toLowerCase());
    expect(tag).toBe("a");
    // Non-image attachments now route through the gated download endpoint
    // introduced in #112; legacy `/media/attachments/...` URLs still resolve
    // but the panel no longer constructs them.
    expect(await link.getAttribute("href")).toMatch(
      /^\/media-objects\/[0-9a-f-]+\/download$/,
    );
  });
});
