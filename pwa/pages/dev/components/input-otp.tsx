import { useState } from "react";
import {
  InputOTP,
  InputOTPGroup,
  InputOTPSeparator,
  InputOTPSlot,
} from "@/components/ui/input-otp";
import ComponentDoc from "@/components/dev/ComponentDoc";

const SixDigit = () => {
  const [value, setValue] = useState("");
  return (
    <InputOTP maxLength={6} value={value} onChange={setValue}>
      <InputOTPGroup>
        <InputOTPSlot index={0} />
        <InputOTPSlot index={1} />
        <InputOTPSlot index={2} />
      </InputOTPGroup>
      <InputOTPSeparator />
      <InputOTPGroup>
        <InputOTPSlot index={3} />
        <InputOTPSlot index={4} />
        <InputOTPSlot index={5} />
      </InputOTPGroup>
    </InputOTP>
  );
};

const InputOtpPage = () => (
  <ComponentDoc
    name="InputOTP"
    description="Segmented one-time-code input built on the `input-otp` package. Compose `InputOTPGroup` + `InputOTPSlot` (indexed) and optional `InputOTPSeparator` to lay out the boxes; the parent owns the value via `value`/`onChange` and caps the length with `maxLength`. Used by the 2FA challenge form."
    importPath={`import {
  InputOTP,
  InputOTPGroup,
  InputOTPSeparator,
  InputOTPSlot,
} from "@/components/ui/input-otp";`}
    examples={[
      {
        title: "Six digits, grouped 3 + 3",
        description:
          "A separator splits the code into two groups. Type to fill the slots left-to-right; the active slot gets a caret and ring.",
        code: `const [value, setValue] = useState("");

<InputOTP maxLength={6} value={value} onChange={setValue}>
  <InputOTPGroup>
    <InputOTPSlot index={0} />
    <InputOTPSlot index={1} />
    <InputOTPSlot index={2} />
  </InputOTPGroup>
  <InputOTPSeparator />
  <InputOTPGroup>
    <InputOTPSlot index={3} />
    <InputOTPSlot index={4} />
    <InputOTPSlot index={5} />
  </InputOTPGroup>
</InputOTP>`,
        preview: <SixDigit />,
      },
    ]}
  />
);

export default InputOtpPage;
