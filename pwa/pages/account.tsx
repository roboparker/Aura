import Head from "next/head";
import { useRouter } from "next/router";
import { useEffect } from "react";
import { useAuth } from "@/contexts/AuthContext";
import AvatarSection from "@/components/account/AvatarSection";
import ProfileForm from "@/components/account/ProfileForm";
import ChangePasswordForm from "@/components/account/ChangePasswordForm";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";

const Account = () => {
  const { user, isAuthenticated, isLoading, logout } = useAuth();
  const router = useRouter();

  useEffect(() => {
    if (!isLoading && !isAuthenticated) {
      router.push("/signin");
    }
  }, [isLoading, isAuthenticated, router]);

  if (isLoading) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-muted">
        <p className="text-muted-foreground">Loading...</p>
      </div>
    );
  }

  if (!user) {
    return null;
  }

  return (
    <>
      <Head>
        <title>My Account - Aura</title>
      </Head>
      <div className="min-h-screen bg-muted px-4 py-12">
        <Card className="max-w-md mx-auto">
          <CardHeader>
            <CardTitle className="text-2xl">My Account</CardTitle>
          </CardHeader>
          <CardContent>
            <AvatarSection />

            <ProfileForm />

            <div className="mt-6 space-y-4">
              <div>
                <p className="text-sm font-medium text-muted-foreground">Email</p>
                <p>{user.email}</p>
              </div>

              <div>
                <p className="text-sm font-medium text-muted-foreground">Roles</p>
                <div className="flex gap-2 mt-1">
                  {user.roles.map((role) => (
                    <Badge key={role} variant="secondary">
                      {role}
                    </Badge>
                  ))}
                </div>
              </div>
            </div>

            <ChangePasswordForm />

            <Button
              variant="destructive"
              className="mt-8 w-full"
              onClick={() => {
                logout();
                router.push("/");
              }}
            >
              Sign Out
            </Button>
          </CardContent>
        </Card>
      </div>
    </>
  );
};

export default Account;
