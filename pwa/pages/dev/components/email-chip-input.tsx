import { useState } from "react";
import EmailChipInput from "@/components/common/EmailChipInput";
import { Label } from "@/components/ui/label";
import ComponentDoc from "@/components/dev/ComponentDoc";

const Basic = () => {
  const [emails, setEmails] = useState<string[]>(["ada@example.com"]);
  return (
    <div className="space-y-1.5">
      <Label htmlFor="invite-emails">Invite by email</Label>
      <EmailChipInput
        inputId="invite-emails"
        value={emails}
        onChange={setEmails}
        placeholder="Type an email…"
      />
    </div>
  );
};

const EmailChipInputPage = () => (
  <ComponentDoc
    name="EmailChipInput"
    description="Controlled multi-email chip input backed by an external `value` array, so the caller owns state and can submit the list directly. Enter, comma, or Tab chips the draft; Backspace on an empty input removes the last chip; pasting a comma/whitespace-separated list splits into chips. Invalid emails keep focus and flash a red ring without blocking the form. Each chip shows a deterministic colored letter tile (no `/users` lookup). An optional `maxItems` caps the list (further chips are silently ignored). Used on the create-space and create-group flows."
    importPath={`import EmailChipInput from "@/components/common/EmailChipInput";`}
    examples={[
      {
        title: "Controlled with a Label",
        description:
          "Pair `inputId` with an external `<Label htmlFor>`. Type an address and press Enter (or comma) to chip it.",
        code: `const [emails, setEmails] = useState<string[]>(["ada@example.com"]);

<Label htmlFor="invite-emails">Invite by email</Label>
<EmailChipInput
  inputId="invite-emails"
  value={emails}
  onChange={setEmails}
  placeholder="Type an email…"
/>`,
        preview: <Basic />,
      },
    ]}
  />
);

export default EmailChipInputPage;
