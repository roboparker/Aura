import SettingsShell from "@/components/settings/SettingsShell";
import CalendarSyncCard from "@/components/settings/CalendarSyncCard";
import { Card, CardContent } from "@/components/ui/card";

const CalendarSyncPage = () => (
  <SettingsShell
    active="calendar-sync"
    title="Calendar sync"
    description="Subscribe to your tasks from Google, Outlook, or Apple Calendar."
  >
    <Card>
      <CardContent className="pt-6">
        <CalendarSyncCard />
      </CardContent>
    </Card>
  </SettingsShell>
);

export default CalendarSyncPage;
