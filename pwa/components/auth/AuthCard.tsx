import { useEffect } from "react";
import Link from "next/link";
import { useRouter } from "next/router";
import { useAuth } from "@/contexts/AuthContext";
import { safeNextPath } from "@/lib/authRedirect";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import AuraWordmark from "./AuraWordmark";
import SignInForm from "./SignInForm";
import SignUpForm from "./SignUpForm";

type Tab = "signin" | "signup";

interface Props {
  /** Which form this page entry point renders. */
  defaultTab: Tab;
}

const COPY: Record<Tab, { title: string; subtitle: string; switchLabel: string; switchCta: string; switchPath: string }> = {
  signin: {
    title: "Welcome back",
    subtitle: "Sign in to your Aura workspace.",
    switchLabel: "New to Aura?",
    switchCta: "Create an account",
    switchPath: "/signup",
  },
  signup: {
    title: "Create your account",
    subtitle: "Get started with Aura in seconds.",
    switchLabel: "Already have an account?",
    switchCta: "Sign in",
    switchPath: "/signin",
  },
};

const AuthCard = ({ defaultTab }: Props) => {
  const router = useRouter();
  const { isAuthenticated, isLoading } = useAuth();

  const next = typeof router.query.next === "string" ? router.query.next : undefined;
  const inviteToken =
    typeof router.query.invite === "string" ? router.query.invite : undefined;
  const registered = router.query.registered === "true";
  const reset = router.query.reset === "true";

  // If a logged-in user lands on /signin or /signup (e.g. via an old
  // bookmark), bounce them straight to wherever `next` points so they
  // don't have to look at a sign-in form they don't need.
  useEffect(() => {
    if (!isLoading && isAuthenticated) {
      router.replace(safeNextPath(next));
    }
  }, [isLoading, isAuthenticated, next, router]);

  const copy = COPY[defaultTab];
  const queryIndex = router.asPath.indexOf("?");
  const search = queryIndex >= 0 ? router.asPath.slice(queryIndex) : "";
  const switchHref = `${copy.switchPath}${search}`;

  return (
    <div className="min-h-screen flex flex-col items-center justify-center bg-background px-4 py-10 gap-8">
      <AuraWordmark />
      <Card className="w-full max-w-lg overflow-hidden p-0">
        <CardHeader className="p-8 pb-6">
          <CardTitle className="text-3xl font-bold">{copy.title}</CardTitle>
          <CardDescription className="text-base">{copy.subtitle}</CardDescription>
        </CardHeader>
        <CardContent className="p-8 pt-2">
          {defaultTab === "signin" ? (
            <SignInForm next={next} registered={registered} reset={reset} />
          ) : (
            <SignUpForm inviteToken={inviteToken} next={next} />
          )}
        </CardContent>
        <div className="border-t border-border bg-background px-8 py-5 text-center text-sm text-muted-foreground">
          {copy.switchLabel}{" "}
          <Link href={switchHref} className="text-primary font-semibold hover:text-foreground">
            {copy.switchCta}
          </Link>
        </div>
      </Card>
      <p className="text-xs text-muted-foreground tracking-wide">
        <a href="/privacy" className="hover:text-foreground">privacy</a>
        <span className="mx-2 opacity-60">•</span>
        <a href="/terms" className="hover:text-foreground">terms</a>
      </p>
    </div>
  );
};

export default AuthCard;
