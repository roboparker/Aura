import type { GetStaticProps, NextPage } from "next";
import Head from "next/head";
import Link from "next/link";
import { formatBlogDate, type BlogPostSummary } from "@/lib/blogTypes";
import { getPostSummaries, siteUrl } from "@/lib/blog";

interface Props {
  posts: BlogPostSummary[];
  origin: string;
}

const PAGE_TITLE = "Blog — Madori";
const PAGE_DESCRIPTION =
  "Tips, tricks, and progress updates from the team building Madori — the API-first workspace for tasks, docs, and discussions.";

const BlogIndex: NextPage<Props> = ({ posts, origin }) => {
  const canonical = `${origin}/blog`;

  return (
    <>
      <Head>
        <title>{PAGE_TITLE}</title>
        <meta name="description" content={PAGE_DESCRIPTION} />
        <link rel="canonical" href={canonical} />
        <meta property="og:type" content="website" />
        <meta property="og:title" content={PAGE_TITLE} />
        <meta property="og:description" content={PAGE_DESCRIPTION} />
        <meta property="og:url" content={canonical} />
        <meta name="twitter:card" content="summary" />
        <meta name="twitter:title" content={PAGE_TITLE} />
        <meta name="twitter:description" content={PAGE_DESCRIPTION} />
      </Head>

      <main className="bg-background">
        <div className="mx-auto max-w-3xl px-6 py-10 md:py-16">
          <header className="mb-10 border-b pb-6">
            <p className="text-xs uppercase tracking-wide text-muted-foreground">
              Madori
            </p>
            <h1 className="mt-1 text-3xl md:text-4xl font-bold tracking-tight">
              Blog
            </h1>
            <p className="mt-2 text-muted-foreground">
              Tips, tricks, and progress updates.
            </p>
          </header>

          {posts.length === 0 ? (
            <p className="text-muted-foreground">No posts yet — check back soon.</p>
          ) : (
            <ul className="space-y-10">
              {posts.map((post) => (
                <li key={post.slug}>
                  <article>
                    <p className="text-xs uppercase tracking-wide text-muted-foreground">
                      {formatBlogDate(post.date)}
                      {post.author ? ` · ${post.author}` : ""}
                      {post.draft ? " · DRAFT" : ""}
                    </p>
                    <h2 className="mt-1 text-xl md:text-2xl font-semibold tracking-tight">
                      <Link
                        href={`/blog/${post.slug}`}
                        className="hover:text-cyan-700 dark:hover:text-cyan-400"
                      >
                        {post.title}
                      </Link>
                    </h2>
                    {post.description ? (
                      <p className="mt-2 text-muted-foreground">
                        {post.description}
                      </p>
                    ) : null}
                    <Link
                      href={`/blog/${post.slug}`}
                      className="mt-3 inline-block text-sm font-medium text-cyan-700 dark:text-cyan-400 hover:underline"
                    >
                      Read more →
                    </Link>
                  </article>
                </li>
              ))}
            </ul>
          )}
        </div>
      </main>
    </>
  );
};

export const getStaticProps: GetStaticProps<Props> = async () => ({
  props: {
    posts: await getPostSummaries(),
    origin: siteUrl(),
  },
});

export default BlogIndex;
