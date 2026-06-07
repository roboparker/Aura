import { useState } from "react";
import ColorSwatchPicker from "@/components/common/ColorSwatchPicker";
import ComponentDoc from "@/components/dev/ComponentDoc";

const Basic = () => {
  const [color, setColor] = useState("#0e7490");
  return (
    <ColorSwatchPicker
      value={color}
      onChange={setColor}
      ariaLabel="Avatar color"
    />
  );
};

const Saving = () => {
  const [color, setColor] = useState("#9333ea");
  return (
    <ColorSwatchPicker
      value={color}
      onChange={setColor}
      savingColor={color}
      ariaLabel="Avatar color (saving)"
    />
  );
};

const ColorSwatchPickerPage = () => (
  <ComponentDoc
    name="ColorSwatchPicker"
    description="Stateless `radiogroup` row of round color swatches — the picker shape used for avatar color in account settings and reused on the create/edit space + group surfaces. The caller owns `value` and is notified via `onChange`. Defaults to `AVATAR_PALETTE`; pass `options` to override. `savingColor` pulses the in-flight swatch and `disabled` short-circuits all interaction so a form can stop the user racing an async save."
    importPath={`import ColorSwatchPicker from "@/components/common/ColorSwatchPicker";`}
    examples={[
      {
        title: "Default palette",
        description:
          "The selected swatch scales up with a ring. Click another to change the controlled value.",
        code: `const [color, setColor] = useState("#0e7490");

<ColorSwatchPicker
  value={color}
  onChange={setColor}
  ariaLabel="Avatar color"
/>`,
        preview: <Basic />,
      },
      {
        title: "Saving state",
        description:
          "Pass the in-flight color to `savingColor` to pulse it while an async save resolves.",
        code: `<ColorSwatchPicker
  value={color}
  onChange={setColor}
  savingColor={color}
  ariaLabel="Avatar color"
/>`,
        preview: <Saving />,
      },
    ]}
  />
);

export default ColorSwatchPickerPage;
