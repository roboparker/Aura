import Head from "next/head";
import Link from "next/link";
import { useRouter } from "next/router";
import { useEffect, useState } from "react";
import { useAuth } from "@/contexts/AuthContext";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import { apiSend } from "@/lib/apiClient";
import { trackEvent } from "@/lib/analytics";
import {
  TYPE_META,
  TYPE_ORDER,
  type Feedback,
  type FeedbackType,
} from "@/lib/feedbackTypes";
import MarkdownEditor from "@/components/editor/MarkdownEditor";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { cn } from "@/lib/utils";

const NewFeedbackPage = () => {
  const { user, isAuthenticated, isLoading: authLoading } = useAuth();
  const router = useRouter();

  const [title, setTitle] = useState("");
  const [type, setType] = useState<FeedbackType>("feature");
  const [description, setDescription] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!authLoading && !isAuthenticated) {
      router.replace(signinHrefForCurrent(router.asPath));
    }
  }, [authLoading, isAuthenticated, router]);

  const submit = async () => {
    const trimTitle = title.trim();
    const trimBody = description.trim();
    if (!trimTitle || !trimBody) return;
    setSubmitting(true);
    setError(null);
    try {
      const created = await apiSend<Feedback>("POST", "/feedback", {
        body: { title: trimTitle, description, type },
        errorMessage: "Failed to submit feedback.",
      });
      trackEvent("feedback-create");
      if (created?.id) {
        await router.push(`/feedback/${created.id}`);
      } else {
        await router.push("/feedback");
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to submit feedback.");
      setSubmitting(false);
    }
  };

  if (authLoading || !user) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-muted">
        <p className="text-muted-foreground">Loading…</p>
      </div>
    );
  }

  return (
    <>
      <Head>
        <title>New feedback - Madori</title>
      </Head>
      <div className="min-h-screen bg-background">
        <div className="max-w-5xl mx-auto px-4 py-8 space-y-4">
          <h1 className="text-2xl font-bold">New feedback</h1>

          <Card>
            <CardContent className="pt-6 space-y-4">
              <div className="space-y-1.5">
                <Label>Type</Label>
                <div className="flex gap-2" role="radiogroup" aria-label="Feedback type">
                  {TYPE_ORDER.map((t) => (
                    <button
                      key={t}
                      type="button"
                      role="radio"
                      aria-checked={type === t}
                      onClick={() => setType(t)}
                      className={cn(
                        "rounded-md border px-4 py-2 text-sm font-medium transition-colors",
                        type === t
                          ? "border-cyan-700 bg-cyan-700 text-white"
                          : "border-input bg-background hover:bg-accent",
                      )}
                      data-testid={`feedback-type-${t}`}
                    >
                      {TYPE_META[t].label}
                    </button>
                  ))}
                </div>
              </div>

              <div className="space-y-1.5">
                <Label htmlFor="feedback-title">Title</Label>
                <Input
                  id="feedback-title"
                  value={title}
                  onChange={(e) => setTitle(e.target.value)}
                  placeholder={
                    type === "bug"
                      ? "Briefly, what went wrong?"
                      : "Briefly, what would you like?"
                  }
                  maxLength={200}
                  required
                  data-testid="feedback-title"
                />
              </div>

              <div className="space-y-1.5">
                <Label>Description</Label>
                <MarkdownEditor
                  ariaLabel="Feedback description"
                  value={description}
                  onChange={setDescription}
                />
              </div>

              {error && (
                <Alert variant="destructive">
                  <AlertDescription>{error}</AlertDescription>
                </Alert>
              )}

              <div className="flex gap-2">
                <Button
                  type="button"
                  onClick={() => void submit()}
                  disabled={submitting || !title.trim() || !description.trim()}
                  data-testid="feedback-submit"
                >
                  {submitting ? "Submitting…" : "Submit feedback"}
                </Button>
                <Button asChild variant="outline" disabled={submitting}>
                  <Link href="/feedback">Cancel</Link>
                </Button>
              </div>
            </CardContent>
          </Card>
        </div>
      </div>
    </>
  );
};

export default NewFeedbackPage;
