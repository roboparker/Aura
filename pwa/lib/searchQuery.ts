// Advanced search query tokens. The search input accepts a small DSL —
// `tag:launch-blocker`, `status:open`, `@lin`, `in:private` — mixed with
// free text. On submit the page parses tokens out into the structured
// filter params it already supports (resolving labels → IRIs against the
// loaded tag / user / space lists), leaving only the free text as `q`.
// Tokens are sugar that expand into filter chips; the chips are the
// source of truth thereafter.

export interface ParsedQuery {
  /** Free text (everything that wasn't a recognised token). */
  text: string;
  /** `tag:` values (human labels, resolved to IRIs by the page). */
  tags: string[];
  /** `status:` value, if a valid one was given. */
  status: "" | "open" | "completed";
  /** `@handle` — an email local-part or name fragment. */
  assignee: string | null;
  /** `in:` value — a space name fragment (or "private"). */
  space: string | null;
}

const STATUS_VALUES = new Set(["open", "completed"]);

const stripQuotes = (s: string): string =>
  s.length >= 2 && s.startsWith('"') && s.endsWith('"') ? s.slice(1, -1) : s;

// Tokenise on whitespace but keep a quoted run together, so
// `tag:"launch blocker"` is one token (not `tag:"launch` + `blocker"`).
// A token is either a chunk containing a "…" quoted span, or a plain
// run of non-space characters.
const TOKEN_RE = /[^\s"]*"[^"]*"[^\s"]*|[^\s"]+/g;

/**
 * Splits a raw query into free text + recognised tokens. Unknown tokens
 * (e.g. `has:video`) fall through to free text rather than erroring.
 * Quoted phrases survive on `tag:` / `in:` values, e.g. `tag:"launch blocker"`.
 */
export function parseQuery(raw: string): ParsedQuery {
  const tags: string[] = [];
  let status: ParsedQuery["status"] = "";
  let assignee: string | null = null;
  let space: string | null = null;
  const textParts: string[] = [];

  for (const token of raw.match(TOKEN_RE) ?? []) {
    const lower = token.toLowerCase();
    if (lower.startsWith("tag:")) {
      const v = stripQuotes(token.slice(4));
      if (v) tags.push(v);
    } else if (lower.startsWith("status:")) {
      const v = lower.slice(7);
      if (STATUS_VALUES.has(v)) status = v as ParsedQuery["status"];
    } else if (lower.startsWith("in:")) {
      const v = stripQuotes(token.slice(3));
      if (v) space = v;
    } else if (token.startsWith("@") && token.length > 1) {
      assignee = token.slice(1);
    } else {
      textParts.push(token);
    }
  }

  return { text: textParts.join(" "), tags, status, assignee, space };
}

/** True when the raw query contains at least one recognised token. */
export function hasTokens(raw: string): boolean {
  const p = parseQuery(raw);
  return (
    p.tags.length > 0 || p.status !== "" || p.assignee !== null || p.space !== null
  );
}
