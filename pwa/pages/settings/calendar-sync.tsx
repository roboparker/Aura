import SettingsShell from "@/components/settings/SettingsShell";
import CalendarSyncCard from "@/components/settings/CalendarSyncCard";
import ConnectedApps from "@/components/settings/ConnectedApps";
import { Card, CardContent } from "@/components/ui/card";

const CalendarSyncPage = () => (
  <SettingsShell
    active="calendar-sync"
    title="Calendar sync"
    description="Subscribe to your tasks, or connect an account for two-way sync."
  >
    <Card>
      <CardContent className="pt-6">
        <ConnectedApps />
      </CardContent>
    </Card>
    <Card>
      <CardContent className="pt-6">
        <CalendarSyncCard />
      </CardContent>
    </Card>
  </SettingsShell>
);

export default CalendarSyncPage;
