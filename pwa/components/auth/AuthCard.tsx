import { useEffect, useState } from "react";
import { useRouter } from "next/router";
import { useAuth } from "@/contexts/AuthContext";
import { safeNextPath } from "@/lib/authRedirect";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import SignInForm from "./SignInForm";
import SignUpForm from "./SignUpForm";

type Tab = "signin" | "signup";

interface Props {
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

  const [tab, setTab] = useState<Tab>(defaultTab);
  // Re-sync when navigating between /signin and /signup with shallow routing.
  useEffect(() => {
    setTab(defaultTab);
  }, [defaultTab]);

  return (
    <div className="min-h-screen flex items-center justify-center bg-muted px-4 py-8">
      <Card className="w-full max-w-md">
        <CardHeader>
          <CardTitle className="text-2xl text-center">{titleFor(tab)}</CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          <Tabs value={tab} onValueChange={(value) => setTab(value as Tab)}>
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
