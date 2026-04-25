import { ENTRYPOINT } from "../config/entrypoint";

interface MediaObjectResponse {
  "@id": string;
  variantUrls: { thumb?: string; profile?: string };
}

/**
 * Uploads an avatar file and links it to the given user. Two steps:
 *   1. POST /media-objects (multipart) -> creates a MediaObject
 *   2. PATCH /users/{userId} with `avatar: <IRI>` -> links it
 */
export async function uploadAvatar(file: File, userId: string): Promise<void> {
  const form = new FormData();
  form.append("file", file);

  const uploadRes = await fetch(`${ENTRYPOINT}/media-objects`, {
    method: "POST",
    credentials: "include",
    body: form,
  });

  if (!uploadRes.ok) {
    const data = await uploadRes.json().catch(() => ({}));
    throw new Error(data.detail || data.error || "Upload failed.");
  }

  const media = (await uploadRes.json()) as MediaObjectResponse;

  const linkRes = await fetch(`${ENTRYPOINT}/users/${userId}`, {
    method: "PATCH",
    credentials: "include",
    headers: { "Content-Type": "application/merge-patch+json" },
    body: JSON.stringify({ avatar: media["@id"] }),
  });

  if (!linkRes.ok) {
    const data = await linkRes.json().catch(() => ({}));
    throw new Error(data.detail || data.error || "Failed to save avatar.");
  }
}
