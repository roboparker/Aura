/**
 * Client-safe blog types + helpers. Kept free of any `node:` imports so it
 * can be referenced from the blog page components without pulling the
 * server-only fs loader (`lib/blog.ts`) into the browser bundle — Turbopack
 * can't bundle `node:fs/promises` for the client.
 */

export interface BlogPost {
  slug: string;
  title: string;
  description: string | null;
  /** Path to a committed social-share image, e.g. `/blog/foo.png`. */
  ogImage: string | null;
  /** ISO date (or `YYYY-MM-DD`) from frontmatter. */
  date: string | null;
  author: string | null;
  draft: boolean;
  body: string;
}

/** Listing/summary view — everything but the (potentially large) body. */
export type BlogPostSummary = Omit<BlogPost, "body">;

const MONTHS = [
  "January", "February", "March", "April", "May", "June",
  "July", "August", "September", "October", "November", "December",
];

/** Deterministic UTC date formatting ("June 21, 2026"). */
export function formatBlogDate(iso: string | null): string {
  if (!iso) return "";
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return "";
  return `${MONTHS[d.getUTCMonth()]} ${d.getUTCDate()}, ${d.getUTCFullYear()}`;
}
