import SettingsShell from "@/components/settings/SettingsShell";
import ApiTokensTable from "@/components/settings/ApiTokensTable";
import { Card, CardContent } from "@/components/ui/card";

const ApiTokensPage = () => (
  <SettingsShell
    active="api-tokens"
    title="API tokens"
    description="Personal access tokens for Madori's REST + MCP APIs."
  >
    <Card>
      <CardContent className="pt-6">
        <ApiTokensTable />
      </CardContent>
    </Card>
  </SettingsShell>
);

export default ApiTokensPage;
