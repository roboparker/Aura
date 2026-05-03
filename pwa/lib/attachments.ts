import { ENTRYPOINT } from "@/config/entrypoint";

export const ATTACHMENT_SUPPORTED_MIMES = [
  "image/jpeg",
  "image/png",
  "image/webp",
  "image/gif",
  "application/pdf",
  "text/plain",
  "text/markdown",
  "text/csv",
  "application/zip",
  "application/json",
];

export const ATTACHMENT_MAX_BYTES = 10 * 1024 * 1024;

/**
 * Uploads a single file as a task attachment via `POST /media-objects`
 * (kind=attachment) and returns the resulting MediaObject IRI. Throws an
 * Error with a user-facing message on validation or server failure.
 *
 * Shared between the attachments panel (file picker / drag-drop) and the
 * task-row paste handler so client-side validation stays in one place.
 */
export async function uploadAttachmentFile(file: File): Promise<string> {
  if (file.size > ATTACHMENT_MAX_BYTES) {
    throw new Error(`${file.name} is larger than 10 MB.`);
  }
  if (!ATTACHMENT_SUPPORTED_MIMES.includes(file.type)) {
    throw new Error(
      `${file.name}: unsupported type "${file.type || "unknown"}".`,
    );
  }
  const form = new FormData();
  form.append("file", file);
  form.append("kind", "attachment");
  const res = await fetch(`${ENTRYPOINT}/media-objects`, {
    method: "POST",
    credentials: "include",
    body: form,
  });
  if (!res.ok) {
    const data = await res.json().catch(() => ({}));
    throw new Error(
      data.detail ||
        data.description ||
        data.error ||
        `Upload failed (${res.status}).`,
    );
  }
  const media = (await res.json()) as { "@id": string };
  return media["@id"];
}

/**
 * Synthesises a filename for clipboard images that arrive without one
 * (e.g. native screenshot tools). Format: `pasted-YYYY-MM-DDTHH-MM-SS.<ext>`
 * — colons in the ISO timestamp are replaced so the name is safe across
 * filesystems.
 */
export function synthesizePastedImageName(mimeType: string): string {
  const ext = mimeType.split("/")[1] || "png";
  const stamp = new Date()
    .toISOString()
    .replace(/[:.]/g, "-")
    .replace(/Z$/, "");
  return `pasted-${stamp}.${ext}`;
}
