import { readdir, readFile } from "node:fs/promises";
import { join, resolve, sep } from "node:path";

/**
 * File-based blog. Posts are markdown files committed under
 * `pwa/content/blog/*.md` with YAML-ish frontmatter, so the content lives
 * in version control (history, diffs, PR review) and ships as static HTML.
 *
 * Server-only — imported solely from `getStaticProps` / `getStaticPaths`
 * (and the build-time sitemap script). The prod image doesn't copy the
 * `content/` tree, so nothing here may run at request time; the pages it
 * feeds are statically generated at build.
 */

const BLOG_ROOT = resolve(process.cwd(), "content", "blog");

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

/**
 * Minimal frontmatter parser. Handles a leading `---` fenced block of
 * `key: value` lines (quoted or bare strings, and `true`/`false`). Good
 * enough for our controlled post format without pulling in gray-matter.
 */
function parseFrontmatter(raw: string): {
  data: Record<string, string | boolean>;
  body: string;
} {
  const match = /^---\r?\n([\s\S]*?)\r?\n---\r?\n?([\s\S]*)$/.exec(raw);
  if (!match) return { data: {}, body: raw };

  const data: Record<string, string | boolean> = {};
  for (const line of match[1].split(/\r?\n/)) {
    const trimmed = line.trim();
    if (trimmed === "" || trimmed.startsWith("#")) continue;
    const sep = trimmed.indexOf(":");
    if (sep === -1) continue;
    const key = trimmed.slice(0, sep).trim();
    let value = trimmed.slice(sep + 1).trim();
    if (
      (value.startsWith('"') && value.endsWith('"')) ||
      (value.startsWith("'") && value.endsWith("'"))
    ) {
      value = value.slice(1, -1);
    }
    if (value === "true" || value === "false") {
      data[key] = value === "true";
    } else {
      data[key] = value;
    }
  }
  return { data, body: match[2] };
}

const str = (
  data: Record<string, string | boolean>,
  key: string,
): string | null => {
  const v = data[key];
  return typeof v === "string" && v.trim() !== "" ? v : null;
};

const titleFromSlug = (slug: string): string =>
  slug
    .split("-")
    .map((p) => (p.length > 0 ? p[0].toUpperCase() + p.slice(1) : p))
    .join(" ");

function toPost(filename: string, raw: string): BlogPost {
  const { data, body } = parseFrontmatter(raw);
  const fileSlug = filename.replace(/\.md$/, "");
  const slug = str(data, "slug") ?? fileSlug;
  return {
    slug,
    title: str(data, "title") ?? titleFromSlug(fileSlug),
    description: str(data, "description"),
    ogImage: str(data, "ogImage"),
    date: str(data, "date"),
    author: str(data, "author"),
    draft: data.draft === true,
    body: body.trim(),
  };
}

/** Drafts are visible locally for preview but never in a production build. */
const includeDrafts = process.env.NODE_ENV !== "production";

async function readAll(): Promise<BlogPost[]> {
  let files: string[];
  try {
    files = await readdir(BLOG_ROOT);
  } catch {
    return [];
  }
  const posts: BlogPost[] = [];
  for (const name of files) {
    if (!name.endsWith(".md")) continue;
    const raw = await readFile(join(BLOG_ROOT, name), "utf8");
    posts.push(toPost(name, raw));
  }
  return posts;
}

const byDateDesc = (a: BlogPost, b: BlogPost): number =>
  (b.date ?? "").localeCompare(a.date ?? "");

/** All publishable posts, newest first. */
export async function getAllPosts(): Promise<BlogPost[]> {
  const posts = await readAll();
  return posts
    .filter((p) => includeDrafts || !p.draft)
    .sort(byDateDesc);
}

/** Listing summaries (no body) — keeps the page payload small. */
export async function getPostSummaries(): Promise<BlogPostSummary[]> {
  const posts = await getAllPosts();
  return posts.map((p) => ({
    slug: p.slug,
    title: p.title,
    description: p.description,
    ogImage: p.ogImage,
    date: p.date,
    author: p.author,
    draft: p.draft,
  }));
}

export async function getPostBySlug(slug: string): Promise<BlogPost | null> {
  // Defence in depth — Next normalises params, but we join onto an fs path.
  if (slug.includes("..") || slug.includes(sep) || slug.includes("/")) {
    return null;
  }
  const posts = await readAll();
  const post = posts.find((p) => p.slug === slug);
  if (!post) return null;
  if (post.draft && !includeDrafts) return null;
  return post;
}

/**
 * Build-time site origin for absolute canonical / OpenGraph URLs.
 * `NEXT_PUBLIC_SITE_URL` is committed in `pwa/.env.production`; falls back
 * to localhost for dev. Trailing slash trimmed.
 */
export function siteUrl(): string {
  return (process.env.NEXT_PUBLIC_SITE_URL ?? "http://localhost:3000").replace(
    /\/$/,
    "",
  );
}

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
