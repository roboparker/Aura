import SettingsShell from "@/components/settings/SettingsShell";
import ApiTokensTable from "@/components/settings/ApiTokensTable";
import UsageSummary from "@/components/settings/UsageSummary";
import { Card, CardContent } from "@/components/ui/card";

const ApiTokensPage = () => (
  <SettingsShell
    active="api-tokens"
    title="API tokens"
    description="Personal access tokens for Madori's REST + MCP APIs."
  >
    <Card>
      <CardContent className="pt-6 space-y-6">
        <UsageSummary />
        <ApiTokensTable />
      </CardContent>
    </Card>
  </SettingsShell>
);

export default ApiTokensPage;
