import React from "react";

/**
 * Splits `text` into <mark>-wrapped runs for any word from `query` that
 * appears in it (case-insensitive substring match). Used by the search
 * autocomplete dropdown and the /search results page so the same
 * highlighting logic stays in one place.
 *
 * The match heuristic intentionally mirrors the user's input rather than
 * reproducing the exact terms the Postgres FTS query matched — `ts_rank`
 * doesn't expose a token list, and a slightly looser visual highlight
 * is closer to what users expect ("show me where my words appear").
 */
export function highlightMatches(text: string, query: string): React.ReactNode {
  const trimmed = query.trim();
  if (!trimmed) return text;

  const words = trimmed
    .split(/\s+/)
    .filter((w) => w.length > 0)
    .map(escapeRegExp);
  if (words.length === 0) return text;

  const pattern = new RegExp(`(${words.join("|")})`, "gi");
  const parts = text.split(pattern);

  return parts.map((part, i) =>
    pattern.test(part) ? (
      <mark
        key={i}
        className="bg-yellow-200 text-foreground rounded px-0.5 dark:bg-yellow-500/40"
      >
        {part}
      </mark>
    ) : (
      <React.Fragment key={i}>{part}</React.Fragment>
    ),
  );
}

function escapeRegExp(s: string): string {
  return s.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
}
