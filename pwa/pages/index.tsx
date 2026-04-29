import Head from "next/head";
import Link from "next/link";
import {
  ArrowRight,
  FileText,
  FolderKanban,
  ImagePlus,
  ListTodo,
  Tag,
  Users,
} from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import {
  Card,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";

const features = [
  {
    icon: ListTodo,
    title: "Tasks that stay tidy",
    description:
      "Capture work in seconds, mark it done with one click, reorder with drag-and-drop. Tags and due dates keep the noise down.",
  },
  {
    icon: FolderKanban,
    title: "Projects with context",
    description:
      "Group related tasks into projects with a shared description, members, and a single timeline of what's open and what's done.",
  },
  {
    icon: Users,
    title: "Groups for any team",
    description:
      "Reusable people-sets you control. Share a group with a project once and every member gets access — no per-task fiddling.",
  },
  {
    icon: Tag,
    title: "Tags that travel",
    description:
      "Color-coded labels you define once and reach for everywhere. Filter, focus, and find work the way you actually think about it.",
  },
  {
    icon: FileText,
    title: "Markdown, by default",
    description:
      "Real rich-text editing for descriptions and notes — headings, code, links, lists. Stored as plain markdown, never locked in.",
  },
  {
    icon: ImagePlus,
    title: "Avatars and identity",
    description:
      "Upload an avatar or fall back to a personalised colour. Members feel like people, not rows in a database.",
  },
];

const Home = () => {
  const { isAuthenticated } = useAuth();

  return (
    <>
      <Head>
        <title>Aura — collaborative task management</title>
        <meta
          name="description"
          content="Aura is a calm, collaborative task manager for small teams. Tasks, projects, groups, tags, and markdown — without the bloat."
        />
      </Head>

      <main>
        {/* Hero */}
        <section className="border-b bg-background">
          <div className="max-w-5xl mx-auto px-4 py-20 md:py-28 text-center">
            <Badge variant="secondary" className="mb-4">
              Now in beta
            </Badge>
            <h1 className="text-4xl md:text-6xl font-bold tracking-tight mb-4 text-foreground">
              Tasks, projects, and people — in one calm place.
            </h1>
            <p className="text-lg md:text-xl text-muted-foreground max-w-2xl mx-auto mb-8">
              Aura is a collaborative task manager for small teams. Share
              projects with groups, write notes in markdown, and never lose
              track of what matters.
            </p>
            <div className="flex flex-wrap items-center justify-center gap-2">
              {isAuthenticated ? (
                <Button asChild size="lg">
                  <Link href="/tasks">
                    Go to your tasks <ArrowRight className="ml-1 h-4 w-4" />
                  </Link>
                </Button>
              ) : (
                <>
                  <Button asChild size="lg">
                    <Link href="/signup">
                      Get started — it&apos;s free
                      <ArrowRight className="ml-1 h-4 w-4" />
                    </Link>
                  </Button>
                  <Button asChild size="lg" variant="outline">
                    <Link href="/signin">Sign in</Link>
                  </Button>
                </>
              )}
            </div>
          </div>
        </section>

        {/* Features */}
        <section className="border-b bg-muted/40">
          <div className="max-w-5xl mx-auto px-4 py-16 md:py-20">
            <div className="text-center mb-12 max-w-2xl mx-auto">
              <h2 className="text-3xl md:text-4xl font-bold tracking-tight mb-3 text-foreground">
                Everything a small team needs.
              </h2>
              <p className="text-muted-foreground">
                No bloat, no busywork — just the building blocks of getting
                things done together.
              </p>
            </div>
            <div className="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
              {features.map(({ icon: Icon, title, description }) => (
                <Card key={title}>
                  <CardHeader>
                    <div className="mb-2 flex h-10 w-10 items-center justify-center rounded-md bg-primary/10 text-primary">
                      <Icon className="h-5 w-5" />
                    </div>
                    <CardTitle>{title}</CardTitle>
                    <CardDescription>{description}</CardDescription>
                  </CardHeader>
                </Card>
              ))}
            </div>
          </div>
        </section>

        {/* How it works */}
        <section className="border-b bg-background">
          <div className="max-w-5xl mx-auto px-4 py-16 md:py-20">
            <div className="text-center mb-12">
              <h2 className="text-3xl md:text-4xl font-bold tracking-tight text-foreground">
                Up and running in three steps.
              </h2>
            </div>
            <div className="grid md:grid-cols-3 gap-8">
              {[
                {
                  step: 1,
                  title: "Create your account",
                  body: "Free, no credit card. Pick a colour for your avatar and you’re in.",
                },
                {
                  step: 2,
                  title: "Invite your group",
                  body: "Add teammates by email. They get a one-click invite — even if they don’t have an account yet.",
                },
                {
                  step: 3,
                  title: "Ship something",
                  body: "Spin up a project, capture the first task, and watch it move from idea to done.",
                },
              ].map(({ step, title, body }) => (
                <div key={step} className="text-center">
                  <div className="mx-auto mb-3 flex h-10 w-10 items-center justify-center rounded-full bg-primary text-primary-foreground font-semibold">
                    {step}
                  </div>
                  <h3 className="font-semibold mb-1 text-foreground">{title}</h3>
                  <p className="text-sm text-muted-foreground">{body}</p>
                </div>
              ))}
            </div>
          </div>
        </section>

        {/* Final CTA */}
        <section className="bg-muted/40">
          <div className="max-w-3xl mx-auto px-4 py-16 md:py-20 text-center">
            <h2 className="text-3xl md:text-4xl font-bold tracking-tight mb-3 text-foreground">
              Ready when you are.
            </h2>
            <p className="text-muted-foreground mb-6">
              It takes less than a minute to set up your first project.
            </p>
            {isAuthenticated ? (
              <Button asChild size="lg">
                <Link href="/projects">
                  Open your projects
                  <ArrowRight className="ml-1 h-4 w-4" />
                </Link>
              </Button>
            ) : (
              <Button asChild size="lg">
                <Link href="/signup">
                  Create your account
                  <ArrowRight className="ml-1 h-4 w-4" />
                </Link>
              </Button>
            )}
          </div>
        </section>

        <footer className="border-t bg-background">
          <div className="max-w-5xl mx-auto px-4 py-6 text-center text-sm text-muted-foreground">
            Aura · MIT License ·{" "}
            <Link
              href="https://github.com/roboparker/Aura"
              target="_blank"
              rel="noopener noreferrer"
              className="underline-offset-4 hover:underline"
            >
              GitHub
            </Link>
          </div>
        </footer>
      </main>
    </>
  );
};

export default Home;
