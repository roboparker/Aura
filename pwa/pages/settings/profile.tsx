import { useAuth } from "@/contexts/AuthContext";
import { useSettingsSection } from "@/lib/useSettingsSection";
import SettingsShell from "@/components/settings/SettingsShell";
import SectionSaveBar from "@/components/settings/SectionSaveBar";
import TimezoneSelect from "@/components/settings/TimezoneSelect";
import StartPageSelect from "@/components/settings/StartPageSelect";
import { DEFAULT_LANDING } from "@/lib/landingDestination";
import AvatarSection from "@/components/account/AvatarSection";
import EmailChangeForm from "@/components/account/EmailChangeForm";
import ProfileForm from "@/components/account/ProfileForm";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Separator } from "@/components/ui/separator";

const ProfilePage = () => {
  const { user } = useAuth();
  const prefs = user?.preferences;

  // Timezone + start page share a card and save together; the identity forms
  // (email, name) keep their own explicit submit buttons.
  const workspace = useSettingsSection(
    {
      timezone: prefs?.timezone ?? "UTC",
      landing: prefs?.landing ?? DEFAULT_LANDING,
    },
    (next) => ({ timezone: next.timezone, landing: next.landing }),
  );

  return (
    <SettingsShell
      active="profile"
      title="Profile"
      description="How you appear to teammates across Madori."
    >
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
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Workspace</CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          <TimezoneSelect
            value={workspace.draft.timezone}
            onChange={(tz) =>
              workspace.setDraft({ ...workspace.draft, timezone: tz })
            }
            disabled={!user}
          />
          <Separator />
          <StartPageSelect
            value={workspace.draft.landing}
            onChange={(landing) =>
              workspace.setDraft({ ...workspace.draft, landing })
            }
            disabled={!user}
          />
          <SectionSaveBar
            dirty={workspace.dirty}
            status={workspace.saveStatus}
            error={workspace.saveError}
            onSave={() => void workspace.save()}
            onDiscard={workspace.discard}
            testId="settings-workspace-save"
          />
        </CardContent>
      </Card>
    </SettingsShell>
  );
};

export default ProfilePage;
