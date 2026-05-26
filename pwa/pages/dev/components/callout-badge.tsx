import { Check, Clock, ShieldAlert } from "lucide-react";

import { CalloutBadge } from "@/components/ui/callout-badge";
import ComponentDoc from "@/components/dev/ComponentDoc";

const CalloutBadgePage = () => (
  <ComponentDoc
    name="CalloutBadge"
    description="Square tinted box with a tonal border and matching outer glow. Used as the headline visual for status callout cards (the 'this invite has expired' splash, the 'your account is ready' confirmation, etc.). Hosts an icon by default; `shape='text'` widens it for short uppercase labels."
    importPath={`import { CalloutBadge } from "@/components/ui/callout-badge";`}
    examples={[
      {
        title: "Tones",
        code: `<CalloutBadge tone="destructive">
  <Clock className="h-5 w-5" />
</CalloutBadge>
<CalloutBadge tone="primary">
  <Check className="h-5 w-5" />
</CalloutBadge>
<CalloutBadge tone="muted">
  <ShieldAlert className="h-5 w-5" />
</CalloutBadge>`,
        preview: (
          <div className="flex flex-wrap items-center gap-6">
            <CalloutBadge tone="destructive">
              <Clock className="h-5 w-5" aria-hidden />
            </CalloutBadge>
            <CalloutBadge tone="primary">
              <Check className="h-5 w-5" aria-hidden />
            </CalloutBadge>
            <CalloutBadge tone="muted">
              <ShieldAlert className="h-5 w-5" aria-hidden />
            </CalloutBadge>
          </div>
        ),
      },
      {
        title: "Text shape",
        code: `<CalloutBadge tone="destructive" shape="text">expired</CalloutBadge>
<CalloutBadge tone="primary" shape="text">new</CalloutBadge>`,
        preview: (
          <div className="flex flex-wrap items-center gap-4">
            <CalloutBadge tone="destructive" shape="text">
              expired
            </CalloutBadge>
            <CalloutBadge tone="primary" shape="text">
              new
            </CalloutBadge>
          </div>
        ),
      },
    ]}
  />
);

export default CalloutBadgePage;
