#!/usr/bin/env node
// Generates `pwa/public/sitemap.xml` from the committed blog markdown in
// `pwa/content/blog/*.md`. Runs as a predev/prebuild hook (alongside
// sync-docs) so the sitemap is a plain static file in `public/` — the only
// tree the prod image ships besides `.next`. Absolute URLs come from
// NEXT_PUBLIC_SITE_URL (committed in pwa/.env.production); drafts are
// excluded. Idempotent and tolerant of a missing content dir.
import { readdir, readFile, writeFile, mkdir } from "node:fs/promises";
import { resolve, dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

const here = dirname(fileURLToPath(import.meta.url));
const contentDir = resolve(here, "..", "content", "blog");
const outFile = resolve(here, "..", "public", "sitemap.xml");

const siteUrl = (process.env.NEXT_PUBLIC_SITE_URL ?? "http://localhost:3000").replace(/\/$/, "");

// Minimal frontmatter reader — mirrors pwa/lib/blog.ts parseFrontmatter for
// the handful of keys the sitemap needs (slug, date, draft).
function parse(raw) {
  const m = /^---\r?\n([\s\S]*?)\r?\n---\r?\n?/.exec(raw);
  const data = {};
  if (m) {
    for (const line of m[1].split(/\r?\n/)) {
      const t = line.trim();
      if (t === "" || t.startsWith("#")) continue;
      const i = t.indexOf(":");
      if (i === -1) continue;
      const key = t.slice(0, i).trim();
      let value = t.slice(i + 1).trim();
      if ((value.startsWith('"') && value.endsWith('"')) || (value.startsWith("'") && value.endsWith("'"))) {
        value = value.slice(1, -1);
      }
      data[key] = value;
    }
  }
  return data;
}

const isoDate = (v) => {
  if (!v) return null;
  const d = new Date(v);
  return Number.isNaN(d.getTime()) ? null : d.toISOString();
};

let files = [];
try {
  files = await readdir(contentDir);
} catch {
  // No posts yet — still emit a sitemap with the listing page.
}

const entries = [{ loc: `${siteUrl}/blog`, lastmod: null }];
for (const name of files) {
  if (!name.endsWith(".md")) continue;
  const data = parse(await readFile(join(contentDir, name), "utf8"));
  if (data.draft === "true") continue;
  const slug = data.slug && data.slug !== "" ? data.slug : name.replace(/\.md$/, "");
  entries.push({ loc: `${siteUrl}/blog/${slug}`, lastmod: isoDate(data.date) });
}

const body = entries
  .map(
    ({ loc, lastmod }) =>
      `  <url>\n    <loc>${loc}</loc>${lastmod ? `\n    <lastmod>${lastmod}</lastmod>` : ""}\n  </url>`,
  )
  .join("\n");

const xml = `<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n${body}\n</urlset>\n`;

await mkdir(dirname(outFile), { recursive: true });
await writeFile(outFile, xml, "utf8");
console.log(`[gen-blog-sitemap] Wrote ${entries.length} url(s) -> ${outFile} (base ${siteUrl})`);
