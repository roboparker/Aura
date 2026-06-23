import { ENTRYPOINT } from "@/config/entrypoint";

/**
 * Personal access token types. A token acts as its owner, optionally narrowed
 * by `accessPolicy` — the shared none/view/edit-per-category(+item) shape from
 * `App\Security\Access\AccessPolicy`. `null` = unrestricted (acts as owner).
 */
export interface TokenAccessPolicy {
  categories: Record<string, string>;
  items: Record<string, Record<string, string>>;
}

export interface ApiTokenRow {
  "@id": string;
  id: string;
  name: string;
  accessPolicy: TokenAccessPolicy | null;
  lastUsedAt: string | null;
  expiresAt: string | null;
  createdAt: string;
}

export interface ExpiryOption {
  value: string;
  label: string;
  /** Days from now, or null for "never". */
  days: number | null;
}

export const EXPIRY_OPTIONS: ExpiryOption[] = [
  { value: "30", label: "30 days", days: 30 },
  { value: "90", label: "90 days", days: 90 },
  { value: "365", label: "1 year", days: 365 },
  { value: "never", label: "Never", days: null },
];

/** Resolve an ISO expiry timestamp for a days-from-now offset (or null). */
export const expiryIsoFromDays = (days: number | null): string | null => {
  if (days === null) return null;
  const d = new Date();
  d.setDate(d.getDate() + days);
  return d.toISOString();
};

interface Collection<T> {
  member?: T[];
  "hydra:member"?: T[];
}

export const fetchApiTokens = async (): Promise<ApiTokenRow[]> => {
  const res = await fetch(`${ENTRYPOINT}/api-tokens`, {
    credentials: "include",
    headers: { Accept: "application/ld+json" },
  });
  if (!res.ok) throw new Error("Failed to load tokens.");
  const data: Collection<ApiTokenRow> = await res.json();
  return data.member ?? data["hydra:member"] ?? [];
};

export interface CreatedApiToken extends ApiTokenRow {
  plainToken: string;
}

export const createApiToken = async (input: {
  name: string;
  accessPolicy: TokenAccessPolicy | null;
  expiresAt: string | null;
}): Promise<CreatedApiToken> => {
  const res = await fetch(`${ENTRYPOINT}/api-tokens`, {
    method: "POST",
    credentials: "include",
    headers: { "Content-Type": "application/ld+json", Accept: "application/ld+json" },
    body: JSON.stringify(input),
  });
  if (!res.ok) {
    const data = await res.json().catch(() => ({}));
    throw new Error(
      data["hydra:description"] || data.detail || "Failed to create token.",
    );
  }
  return res.json();
};

export const updateApiToken = async (
  iri: string,
  patch: Partial<Pick<ApiTokenRow, "name" | "accessPolicy" | "expiresAt">>,
): Promise<ApiTokenRow> => {
  const res = await fetch(`${ENTRYPOINT}${iri}`, {
    method: "PATCH",
    credentials: "include",
    headers: {
      "Content-Type": "application/merge-patch+json",
      Accept: "application/ld+json",
    },
    body: JSON.stringify(patch),
  });
  if (!res.ok) {
    const data = await res.json().catch(() => ({}));
    throw new Error(
      data["hydra:description"] || data.detail || "Failed to update token.",
    );
  }
  return res.json();
};

export const revokeApiToken = async (iri: string): Promise<void> => {
  const res = await fetch(`${ENTRYPOINT}${iri}`, {
    method: "DELETE",
    credentials: "include",
  });
  if (!res.ok) throw new Error("Failed to revoke token.");
};
