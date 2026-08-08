import type { GetStaticPaths, GetStaticProps, NextPage } from "next";
import Head from "next/head";
import Link from "next/link";
import ReactMarkdown from "react-markdown";
import remarkGfm from "remark-gfm";
import { ChevronLeft } from "lucide-react";
import { Button } from "@/components/ui/button";
import { formatBlogDate, type BlogPost } from "@/lib/blogTypes";
import { getAllPosts, getPostBySlug, siteUrl } from "@/lib/blog";
import { pageTitle } from "@/lib/pageTitle";

interface Props {
  post: BlogPost;
  origin: string;
}

const BlogPostPage: NextPage<Props> = ({ post, origin }) => {
  const canonical = `${origin}/blog/${post.slug}`;
  const description = post.description ?? `${post.title} — from the Madori blog.`;
  const ogImage = post.ogImage ? `${origin}${post.ogImage}` : null;

  return (
    <>
      <Head>
        <title>{pageTitle(post.title)}</title>
        <meta name="description" content={description} />
        <link rel="canonical" href={canonical} />
        {post.draft ? <meta name="robots" content="noindex" /> : null}

        <meta property="og:type" content="article" />
        <meta property="og:title" content={post.title} />
        <meta property="og:description" content={description} />
        <meta property="og:url" content={canonical} />
        {post.date ? (
          <meta property="article:published_time" content={post.date} />
        ) : null}
        {post.author ? (
          <meta property="article:author" content={post.author} />
        ) : null}
        {ogImage ? <meta property="og:image" content={ogImage} /> : null}

        <meta
          name="twitter:card"
          content={ogImage ? "summary_large_image" : "summary"}
        />
        <meta name="twitter:title" content={post.title} />
        <meta name="twitter:description" content={description} />
        {ogImage ? <meta name="twitter:image" content={ogImage} /> : null}
      </Head>

      <div className="bg-background">
        <div className="mx-auto max-w-3xl px-6 py-10 md:py-16">
          <Button asChild variant="ghost" size="sm" className="mb-6 -ml-2 gap-1">
            <Link href="/blog">
              <ChevronLeft className="h-4 w-4" aria-hidden />
              All posts
            </Link>
          </Button>

          <header className="mb-8 border-b pb-6">
            <p className="text-xs uppercase tracking-wide text-muted-foreground">
              {formatBlogDate(post.date)}
              {post.author ? ` · ${post.author}` : ""}
              {post.draft ? " · DRAFT" : ""}
            </p>
            <h1 className="mt-1 text-3xl md:text-4xl font-bold tracking-tight">
              {post.title}
            </h1>
            {post.description ? (
              <p className="mt-3 text-lg text-muted-foreground">
                {post.description}
              </p>
            ) : null}
          </header>

          <article className="doc-content">
            <ReactMarkdown remarkPlugins={[remarkGfm]}>{post.body}</ReactMarkdown>
          </article>
        </div>
      </div>
    </>
  );
};

export const getStaticPaths: GetStaticPaths = async () => {
  const posts = await getAllPosts();
  return {
    paths: posts.map((p) => ({ params: { slug: p.slug } })),
    fallback: false,
  };
};

export const getStaticProps: GetStaticProps<Props> = async (context) => {
  const slug = typeof context.params?.slug === "string" ? context.params.slug : "";
  const post = slug ? await getPostBySlug(slug) : null;
  if (!post) return { notFound: true };
  return {
    props: {
      post,
      origin: siteUrl(),
    },
  };
};

export default BlogPostPage;
