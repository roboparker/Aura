import SettingsShell from "@/components/settings/SettingsShell";
import AccountBillingCard from "@/components/billing/AccountBillingCard";

const BillingSettingsPage = () => (
  <SettingsShell
    active="billing"
    title="Billing"
    description="Your personal plan. Covers the spaces you own personally — team spaces are billed to their organization."
  >
    <AccountBillingCard
      endpointBase="/me/billing"
      upgradeLabel="Pro"
      upgradeBlurb="Unlock calendar sync, time tracking, invoicing, and the timeline across your personal spaces."
    />
  </SettingsShell>
);

export default BillingSettingsPage;
