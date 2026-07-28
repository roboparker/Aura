/** Product name, as it appears in the browser tab. */
export const PRODUCT_NAME = "Madori";

/**
 * Builds a document title.
 *
 * There were three shapes shipping side by side — `Boards - Madori` (28
 * pages), `Dashboard — Madori` (24), and `Roles · Launch team` (which omitted
 * the product name entirely). Tab strips, bookmarks, history and search
 * results all put those next to each other, which is exactly where a product's
 * consistency is on display for free.
 *
 * One rule now: parts are joined with a middle dot, then the product name is
 * appended after an em dash. The two separators do different jobs — the dot
 * separates page context, the dash separates the page from the product — so
 * the title stays parseable at a glance no matter how many parts it has.
 *
 *   pageTitle("Boards")              → "Boards — Madori"
 *   pageTitle("Roles", "Launch team") → "Roles · Launch team — Madori"
 *
 * Empty and nullish parts are dropped, so a caller can pass a value that is
 * still loading without emitting a stray separator.
 */
export function pageTitle(...parts: Array<string | null | undefined>): string {
  const named = parts
    .map((p) => (typeof p === "string" ? p.trim() : ""))
    .filter((p) => p.length > 0);

  return named.length > 0
    ? `${named.join(" · ")} — ${PRODUCT_NAME}`
    : PRODUCT_NAME;
}
