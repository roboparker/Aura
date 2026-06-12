import { useAuth } from "@/contexts/AuthContext";
import { usePreferencePersist } from "@/lib/usePreferencePersist";
import SettingsShell from "@/components/settings/SettingsShell";
import SaveIndicator from "@/components/settings/SaveIndicator";
import TimezoneSelect from "@/components/settings/TimezoneSelect";
import StartPageSelect from "@/components/settings/StartPageSelect";
import { DEFAULT_LANDING } from "@/lib/landingDestination";
import AvatarSection from "@/components/account/AvatarSection";
import EmailChangeForm from "@/components/account/EmailChangeForm";
import ProfileForm from "@/components/account/ProfileForm";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Separator } from "@/components/ui/separator";

const ProfilePage = () => {
  const { user } = useAuth();
  const { persist, saveStatus, saveError } = usePreferencePersist();
  const prefs = user?.preferences;

  return (
    <SettingsShell
      active="profile"
      title="Profile"
      description="How you appear to teammates across Madori."
      actions={<SaveIndicator status={saveStatus} />}
    >
      {saveError && (
        <Alert variant="destructive" data-testid="settings-error">
          <AlertDescription>{saveError}</AlertDescription>
        </Alert>
      )}

      <Card>
        <CardHeader>
          <CardTitle>Avatar</CardTitle>
        </CardHeader>
        <CardContent>
          <AvatarSection />
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Identity</CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          <EmailChangeForm />
          <Separator />
          <ProfileForm />
          <Separator />
          <TimezoneSelect
            value={prefs?.timezone ?? "UTC"}
            onChange={(tz) => void persist({ timezone: tz })}
            disabled={!user}
          />
          <Separator />
          <StartPageSelect
            value={prefs?.landing ?? DEFAULT_LANDING}
            onChange={(landing) => void persist({ landing })}
            disabled={!user}
          />
        </CardContent>
      </Card>
    </SettingsShell>
  );
};

export default ProfilePage;
