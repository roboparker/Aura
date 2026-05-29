import { readdir, readFile, stat } from "node:fs/promises";
import { join, resolve, sep } from "node:path";

// Server-only — only imported from getStaticPaths / getStaticProps. The
// markdown tree is mirrored into `pwa/.content/docs/` by
// `scripts/sync-docs.mjs`, which runs as a predev/prebuild hook.
const DOCS_ROOT = resolve(process.cwd(), ".content", "docs");

export interface DocSection {
  slug: string;
  title: string;
  entries: DocEntry[];
}

export interface DocEntry {
  slug: string[];
  href: string;
  title: string;
  section: string;
}

const SECTION_LABELS: Record<string, string> = {
  developer: "Developer",
  user: "User",
};

const titleFromFilename = (filename: string): string => {
  const base = filename.replace(/\.md$/, "");
  return base
    .split("-")
    .map((part) => (part.length > 0 ? part[0].toUpperCase() + part.slice(1) : part))
    .join(" ");
};

// First `# heading` line wins, otherwise prettify the filename.
const titleFromMarkdown = (body: string, filename: string): string => {
  const match = body.match(/^#\s+(.+?)\s*$/m);
  if (match) return match[1].trim();
  return titleFromFilename(filename);
};

const isMarkdown = (name: string): boolean => name.endsWith(".md");

const walk = async (dir: string, prefix: string[] = []): Promise<DocEntry[]> => {
  let dirents: string[];
  try {
    dirents = await readdir(dir);
  } catch {
    return [];
  }
  const out: DocEntry[] = [];
  for (const name of dirents) {
    const full = join(dir, name);
    const s = await stat(full);
    if (s.isDirectory()) {
      out.push(...(await walk(full, [...prefix, name])));
    } else if (isMarkdown(name)) {
      const body = await readFile(full, "utf8");
      const slug = [...prefix, name.replace(/\.md$/, "")];
      out.push({
        slug,
        href: `/guides/${slug.join("/")}`,
        title: titleFromMarkdown(body, name),
        section: prefix[0] ?? "",
      });
    }
  }
  return out;
};

export const getAllDocs = async (): Promise<DocEntry[]> => walk(DOCS_ROOT);

export const getDocSections = async (): Promise<DocSection[]> => {
  const all = await getAllDocs();
  const bySection = new Map<string, DocEntry[]>();
  for (const entry of all) {
    const list = bySection.get(entry.section) ?? [];
    list.push(entry);
    bySection.set(entry.section, list);
  }
  return Array.from(bySection.entries())
    .sort(([a], [b]) => a.localeCompare(b))
    .map(([slug, entries]) => ({
      slug,
      title: SECTION_LABELS[slug] ?? titleFromFilename(slug),
      entries: entries.sort((a, b) => a.title.localeCompare(b.title)),
    }));
};

export interface LoadedDoc {
  slug: string[];
  title: string;
  body: string;
  section: string;
}

export const loadDoc = async (slug: string[]): Promise<LoadedDoc | null> => {
  // Defence in depth — Next.js already normalises route params, but we're
  // joining user-influenced strings onto a filesystem path.
  if (slug.some((segment) => segment.includes("..") || segment.includes(sep))) {
    return null;
  }
  const path = join(DOCS_ROOT, ...slug) + ".md";
  let body: string;
  try {
    body = await readFile(path, "utf8");
  } catch {
    return null;
  }
  return {
    slug,
    title: titleFromMarkdown(body, slug[slug.length - 1] + ".md"),
    body,
    section: slug[0] ?? "",
  };
};
