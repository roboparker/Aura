import { ENTRYPOINT } from "@/config/entrypoint";

/**
 * Personal access token types + the scope vocabulary, mirrored from the
 * backend `App\Entity\ApiToken::SCOPE_VOCABULARY`. Keep in lock-step — the
 * server rejects unknown scopes with a 422.
 */
export interface ApiTokenRow {
  "@id": string;
  id: string;
  name: string;
  scopes: string[];
  lastUsedAt: string | null;
  expiresAt: string | null;
  createdAt: string;
}

export interface ApiScope {
  value: string;
  label: string;
  description: string;
}

export const API_SCOPES: ApiScope[] = [
  { value: "read:tasks", label: "read:tasks", description: "View tasks, comments, attachments" },
  { value: "write:tasks", label: "write:tasks", description: "Create & edit tasks (and delete)" },
  { value: "read:projects", label: "read:projects", description: "List & read project metadata" },
  { value: "write:projects", label: "write:projects", description: "Create & edit projects" },
  { value: "read:pages", label: "read:pages", description: "Read pages content" },
  { value: "admin", label: "admin", description: "Full read + write across your workspace" },
];

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
  scopes: string[];
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

export const revokeApiToken = async (iri: string): Promise<void> => {
  const res = await fetch(`${ENTRYPOINT}${iri}`, {
    method: "DELETE",
    credentials: "include",
  });
  if (!res.ok) throw new Error("Failed to revoke token.");
};
