import { ENTRYPOINT } from "@/config/entrypoint";
import { fetchWithTimeout } from "@/lib/fetchWithTimeout";

/**
 * Thin wrapper over `fetch` for talking to our API Platform backend.
 *
 * Before this existed every page hand-rolled the same envelope —
 * `${ENTRYPOINT}/...`, `credentials: "include"`, the JSON-LD content
 * type, and an ad-hoc `data.description || data.detail || …` error
 * unwrap. That boilerplate is centralised here:
 *  - `apiGet` / `apiSend` build the URL, attach the session cookie, set
 *    the JSON-LD headers, and throw a typed {@link ApiError} on non-2xx
 *    so callers can `catch` a single shape;
 *  - `apiGetCollection` + {@link readCollection} unwrap the JSON-LD
 *    `hydra:member` envelope;
 *  - everything routes through {@link fetchWithTimeout}, which enforces
 *    the same-origin guard (request-forgery defence, see #371) and a
 *    deadline so a wedged connection surfaces as an error, not a spinner.
 *
 * Pages can migrate to this incrementally — it's additive.
 */

const JSON_LD = "application/ld+json";

/** Generous default; long enough for any CRUD round-trip, short enough that a wedged forward fails loudly. */
const DEFAULT_TIMEOUT_MS = 30_000;

/** JSON-LD hydra collection envelope, tolerating the bare `member` alias too. */
export type HydraCollection<T> = {
  "hydra:member"?: T[];
  "hydra:totalItems"?: number;
  member?: T[];
  totalItems?: number;
};

/** Unwrap a JSON-LD collection (or a bare array) into a plain list. */
export const readCollection = <T>(data: unknown): T[] => {
  if (Array.isArray(data)) return data as T[];
  if (data && typeof data === "object") {
    const obj = data as Record<string, unknown>;
    if (Array.isArray(obj["hydra:member"])) return obj["hydra:member"] as T[];
    if (Array.isArray(obj.member)) return obj.member as T[];
  }
  return [];
};

/** Error thrown for any non-2xx API response. Carries the HTTP status + parsed body. */
export class ApiError extends Error {
  constructor(
    public readonly status: number,
    message: string,
    public readonly body: unknown = null,
  ) {
    super(message);
    this.name = "ApiError";
  }
}

/**
 * Pull a human-readable message out of an API error response, trying the
 * field names API Platform / our controllers use, in priority order.
 */
export async function apiErrorMessage(res: Response, fallback: string): Promise<string> {
  const data: unknown = await res.json().catch(() => null);
  if (data && typeof data === "object") {
    const obj = data as Record<string, unknown>;
    for (const key of ["description", "detail", "hydra:description", "message", "error"]) {
      const value = obj[key];
      if (typeof value === "string" && value.trim() !== "") return value;
    }
  }
  return fallback;
}

export type ApiRequestOptions = {
  /** Request payload — serialised to JSON unless a string is passed. */
  body?: unknown;
  /** Extra headers, merged over the defaults. */
  headers?: Record<string, string>;
  /** Caller-controlled abort signal (overrides the default timeout). */
  signal?: AbortSignal;
  /** Override the request deadline. */
  timeoutMs?: number;
  /** Override the Content-Type for the body (e.g. plain JSON for PATCH merge). */
  contentType?: string;
  /** Fallback message for the thrown {@link ApiError} when the body has none. */
  errorMessage?: string;
};

function buildUrl(path: string): string {
  if (/^https?:\/\//i.test(path)) return path;
  return `${ENTRYPOINT}${path.startsWith("/") ? "" : "/"}${path}`;
}

async function request(method: string, path: string, opts: ApiRequestOptions = {}): Promise<Response> {
  const headers: Record<string, string> = { Accept: JSON_LD, ...opts.headers };
  let body: BodyInit | undefined;
  if (opts.body !== undefined) {
    headers["Content-Type"] = opts.contentType ?? JSON_LD;
    body = typeof opts.body === "string" ? opts.body : JSON.stringify(opts.body);
  }

  const res = await fetchWithTimeout(buildUrl(path), {
    method,
    credentials: "include",
    headers,
    body,
    signal: opts.signal,
    timeoutMs: opts.timeoutMs ?? DEFAULT_TIMEOUT_MS,
  });

  if (!res.ok) {
    throw new ApiError(res.status, await apiErrorMessage(res, opts.errorMessage ?? `Request failed (${res.status}).`));
  }
  return res;
}

/** GET a single resource (or any JSON), throwing {@link ApiError} on failure. */
export async function apiGet<T>(path: string, opts?: ApiRequestOptions): Promise<T> {
  const res = await request("GET", path, opts);
  return (await res.json()) as T;
}

/** GET a JSON-LD collection and return its members as a list. */
export async function apiGetCollection<T>(path: string, opts?: ApiRequestOptions): Promise<T[]> {
  return readCollection<T>(await apiGet<unknown>(path, opts));
}

/**
 * Send a mutating request (POST/PATCH/PUT/DELETE). Returns the parsed JSON
 * body, or null for an empty / 204 response.
 */
export async function apiSend<T = unknown>(
  method: "POST" | "PATCH" | "PUT" | "DELETE",
  path: string,
  opts?: ApiRequestOptions,
): Promise<T | null> {
  const res = await request(method, path, opts);
  if (res.status === 204) return null;
  const text = await res.text();
  return text ? (JSON.parse(text) as T) : null;
}
