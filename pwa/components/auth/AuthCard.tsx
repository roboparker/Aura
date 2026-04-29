import { useEffect } from "react";
import { useRouter } from "next/router";
import { useAuth } from "@/contexts/AuthContext";
import { safeNextPath } from "@/lib/authRedirect";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import SignInForm from "./SignInForm";
import SignUpForm from "./SignUpForm";

type Tab = "signin" | "signup";

interface Props {
  /**
   * Which tab the page entry point selects. The currently-displayed tab
   * is fully URL-driven — `/signin` shows "signin", `/signup` shows
   * "signup", and tab triggers navigate between the two so the active
   * form always matches the URL the user (and tests) can read.
   */
  defaultTab: Tab;
}

const titleFor = (tab: Tab) => (tab === "signin" ? "Welcome back" : "Create your account");

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

  // Tab clicks navigate between /signin and /signup, preserving any
  // existing query string (so `?next=/tasks` and `?invite=xxx` flow
  // through). This keeps the URL the source of truth — clicking Sign Up,
  // submitting, then bouncing back to /signin lands on the *Sign In*
  // form rather than re-showing whatever local state the user last had.
  const switchTab = (target: Tab) => {
    if (target === defaultTab) return;
    const targetPath = target === "signin" ? "/signin" : "/signup";
    const queryIndex = router.asPath.indexOf("?");
    const search = queryIndex >= 0 ? router.asPath.slice(queryIndex) : "";
    router.push(`${targetPath}${search}`);
  };

  return (
    <div className="min-h-screen flex items-center justify-center bg-muted px-4 py-8">
      <Card className="w-full max-w-md">
        <CardHeader>
          <CardTitle className="text-2xl text-center">{titleFor(defaultTab)}</CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          <Tabs value={defaultTab} onValueChange={(value) => switchTab(value as Tab)}>
            <TabsList className="grid grid-cols-2 w-full">
              <TabsTrigger value="signin">Sign In</TabsTrigger>
              <TabsTrigger value="signup">Sign Up</TabsTrigger>
            </TabsList>
            <TabsContent value="signin" className="mt-4">
              <SignInForm next={next} registered={registered} reset={reset} />
            </TabsContent>
            <TabsContent value="signup" className="mt-4">
              <SignUpForm inviteToken={inviteToken} next={next} />
            </TabsContent>
          </Tabs>
        </CardContent>
      </Card>
    </div>
  );
};

export default AuthCard;
