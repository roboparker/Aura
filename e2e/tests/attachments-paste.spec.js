// @ts-check
const { test, expect } = require("@playwright/test");
const {
  BASE_URL,
  uniqueEmail: shared,
  registerAndSignIn,
  createTaskInline,
} = require("./helpers");

const uniqueEmail = () => shared("attach-paste");

// Smallest valid 1x1 PNG.
const TINY_PNG_BASE64 =
  "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==";

/**
 * Synthesise a paste event carrying a real PNG file payload, then dispatch
 * it on the focused element. Playwright doesn't expose a "real" clipboard
 * with file items, so we build the DataTransfer in-page from a base64 PNG
 * and let the React handler treat it like a native paste.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} testid data-testid of the element to dispatch on
 * @param {{ pngBase64: string, mimeType: string, filename?: string }} opts
 */
async function dispatchImagePaste(page, testid, opts) {
  await page.evaluate(
    ({ testid, pngBase64, mimeType, filename }) => {
      const target = document.querySelector(`[data-testid="${testid}"]`);
      if (!(target instanceof HTMLElement)) {
        throw new Error(`Target [data-testid="${testid}"] not found`);
      }
      const bytes = Uint8Array.from(atob(pngBase64), (c) => c.charCodeAt(0));
      const file = new File([bytes], filename || "image.png", {
        type: mimeType,
      });
      const dt = new DataTransfer();
      dt.items.add(file);
      const event = new ClipboardEvent("paste", {
        bubbles: true,
        cancelable: true,
        clipboardData: dt,
      });
      target.dispatchEvent(event);
    },
    { testid, pngBase64: opts.pngBase64, mimeType: opts.mimeType, filename: opts.filename },
  );
}

test.describe("Paste-to-upload image attachments", () => {
  test("pasting an image into the description editor uploads it as an attachment", async ({
    page,
  }) => {
    await registerAndSignIn(page, uniqueEmail());
    await page.goto(`${BASE_URL}/tasks`);

    const title = `Paste ${Date.now()}`;
    await createTaskInline(page, title);
    const item = page.locator('[data-testid="task-item"]', { hasText: title });

    // Open the description editor so its DOM exists in the row.
    await item.locator('[data-testid="task-description-add"]').click();

    // Dispatch a synthesized paste at the row level — the React handler is
    // attached to the <tbody>, so events anywhere inside the row bubble up.
    await dispatchImagePaste(page, "task-item", {
      pngBase64: TINY_PNG_BASE64,
      mimeType: "image/png",
      filename: "screenshot.png",
    });

    // The attachments panel auto-opens on paste, and the upload appears.
    const panel = item.locator('[data-testid="attachments-panel"]');
    await expect(panel).toBeVisible();
    await expect(panel.locator('[data-testid="attachment-item"]')).toHaveCount(1);
    await expect(
      item.locator('[data-testid="task-attachments-toggle"]'),
    ).toContainText("Attachments (1)");
  });

  test("pasting plain text does not upload anything", async ({ page }) => {
    await registerAndSignIn(page, uniqueEmail());
    await page.goto(`${BASE_URL}/tasks`);

    const title = `Paste-text ${Date.now()}`;
    await createTaskInline(page, title);
    const item = page.locator('[data-testid="task-item"]', { hasText: title });

    // Open the attachments panel so we can assert the count stays at 0.
    await item.locator('[data-testid="task-attachments-toggle"]').click();
    const panel = item.locator('[data-testid="attachments-panel"]');
    await expect(panel.locator('[data-testid="attachment-item"]')).toHaveCount(0);

    // Dispatch a paste with text only (no file items).
    await page.evaluate(() => {
      const target = document.querySelector('[data-testid="task-item"]');
      if (!(target instanceof HTMLElement)) throw new Error("no row");
      const dt = new DataTransfer();
      dt.setData("text/plain", "Just some text.");
      const event = new ClipboardEvent("paste", {
        bubbles: true,
        cancelable: true,
        clipboardData: dt,
      });
      target.dispatchEvent(event);
    });

    // No upload, no attachment row.
    await expect(panel.locator('[data-testid="attachment-item"]')).toHaveCount(0);
    await expect(
      item.locator('[data-testid="task-attachments-toggle"]'),
    ).toContainText("Attach");
  });

  test("pasting an image with no filename uses a synthesised name", async ({
    page,
  }) => {
    await registerAndSignIn(page, uniqueEmail());
    await page.goto(`${BASE_URL}/tasks`);

    const title = `Paste-noname ${Date.now()}`;
    await createTaskInline(page, title);
    const item = page.locator('[data-testid="task-item"]', { hasText: title });

    // Native screenshot tools commonly hand the browser a File whose name is
    // the literal "image.png" placeholder — our handler treats that as
    // "no name" and synthesises a timestamped filename instead.
    await dispatchImagePaste(page, "task-item", {
      pngBase64: TINY_PNG_BASE64,
      mimeType: "image/png",
      filename: "image.png",
    });

    const panel = item.locator('[data-testid="attachments-panel"]');
    await expect(panel.locator('[data-testid="attachment-item"]')).toHaveCount(1);
    // Filename starts with the synthesized "pasted-" prefix.
    await expect(
      panel.locator('[data-testid="attachment-item"]').first(),
    ).toContainText(/pasted-\d{4}-\d{2}-\d{2}/);
  });
});
