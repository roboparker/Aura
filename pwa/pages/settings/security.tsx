import { useAuth } from "@/contexts/AuthContext";
import SettingsShell from "@/components/settings/SettingsShell";
import ChangePasswordForm from "@/components/account/ChangePasswordForm";
import TwoFactorSection from "@/components/account/TwoFactorSection";
import ActiveSessionsTable from "@/components/settings/ActiveSessionsTable";
import ImpersonationConsent from "@/components/settings/ImpersonationConsent";
import ConnectedAccounts from "@/components/settings/ConnectedAccounts";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Separator } from "@/components/ui/separator";

const SecurityPage = () => {
  const { user } = useAuth();

  return (
    <SettingsShell
      active="security"
      title="Security"
      description="Password, two-factor authentication, and active sessions."
    >
      <Card>
        <CardHeader>
          <CardTitle>Account security</CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          <div>
            <p className="text-sm font-medium text-muted-foreground">Roles</p>
            <div className="mt-1 flex gap-2">
              {(user?.roles ?? []).map((role) => (
                <Badge key={role} variant="secondary">
                  {role}
                </Badge>
              ))}
            </div>
          </div>
          <Separator />
          <ChangePasswordForm />
          <Separator />
          <TwoFactorSection />
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Connected accounts</CardTitle>
        </CardHeader>
        <CardContent>
          <ConnectedAccounts />
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Privacy</CardTitle>
        </CardHeader>
        <CardContent>
          <ImpersonationConsent />
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Active sessions</CardTitle>
        </CardHeader>
        <CardContent>
          <ActiveSessionsTable />
        </CardContent>
      </Card>
    </SettingsShell>
  );
};

export default SecurityPage;
