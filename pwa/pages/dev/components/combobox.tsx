import { useState } from "react";
import {
  Combobox,
  ComboboxChip,
  ComboboxChips,
  ComboboxChipsInput,
  ComboboxContent,
  ComboboxEmpty,
  ComboboxInput,
  ComboboxItem,
  ComboboxList,
  ComboboxValue,
} from "@/components/ui/combobox";
import ComponentDoc from "@/components/dev/ComponentDoc";

const fruits = [
  { id: "apple", label: "Apple" },
  { id: "banana", label: "Banana" },
  { id: "cherry", label: "Cherry" },
  { id: "date", label: "Date" },
];

type Fruit = (typeof fruits)[number];

const SingleSelect = () => {
  const [value, setValue] = useState<Fruit | null>(null);
  return (
    <Combobox<Fruit, false>
      items={fruits}
      value={value}
      onValueChange={setValue}
      itemToStringLabel={(f) => f.label}
      itemToStringValue={(f) => f.id}
    >
      <ComboboxInput placeholder="Pick a fruit…" />
      <ComboboxContent>
        <ComboboxEmpty>No matches.</ComboboxEmpty>
        <ComboboxList>
          {(fruit: Fruit) => (
            <ComboboxItem key={fruit.id} value={fruit}>
              {fruit.label}
            </ComboboxItem>
          )}
        </ComboboxList>
      </ComboboxContent>
    </Combobox>
  );
};

const MultiSelect = () => {
  const [value, setValue] = useState<Fruit[]>([fruits[0]]);
  return (
    <Combobox<Fruit, true>
      items={fruits}
      multiple
      value={value}
      onValueChange={setValue}
      itemToStringLabel={(f) => f.label}
      itemToStringValue={(f) => f.id}
    >
      <ComboboxChips>
        <ComboboxValue>
          {value.map((fruit) => (
            <ComboboxChip key={fruit.id} aria-label={fruit.label}>
              {fruit.label}
            </ComboboxChip>
          ))}
        </ComboboxValue>
        <ComboboxChipsInput placeholder="Add fruit…" />
      </ComboboxChips>
      <ComboboxContent>
        <ComboboxEmpty>No matches.</ComboboxEmpty>
        <ComboboxList>
          {(fruit: Fruit) => (
            <ComboboxItem key={fruit.id} value={fruit}>
              {fruit.label}
            </ComboboxItem>
          )}
        </ComboboxList>
      </ComboboxContent>
    </Combobox>
  );
};

const ComboboxPage = () => (
  <ComponentDoc
    name="Combobox"
    description="Searchable select built on @base-ui/react. Pass `multiple` for chip-style multi-select. See pwa/components/tasks/TagsCombobox.tsx for a real-world chips example."
    importPath={`import { Combobox, ComboboxInput, ComboboxContent, ComboboxList, ComboboxItem, ComboboxEmpty, ComboboxChips, ComboboxChip, ComboboxChipsInput, ComboboxValue } from "@/components/ui/combobox";`}
    examples={[
      {
        title: "Single-select",
        code: `const [value, setValue] = useState<Fruit | null>(null);

<Combobox<Fruit, false>
  items={fruits}
  value={value}
  onValueChange={setValue}
  itemToStringLabel={(f) => f.label}
  itemToStringValue={(f) => f.id}
>
  <ComboboxInput placeholder="Pick a fruit…" />
  <ComboboxContent>
    <ComboboxEmpty>No matches.</ComboboxEmpty>
    <ComboboxList>
      {(fruit: Fruit) => (
        <ComboboxItem key={fruit.id} value={fruit}>
          {fruit.label}
        </ComboboxItem>
      )}
    </ComboboxList>
  </ComboboxContent>
</Combobox>`,
        preview: <SingleSelect />,
      },
      {
        title: "Multi-select (chips)",
        code: `const [value, setValue] = useState<Fruit[]>([fruits[0]]);

<Combobox<Fruit, true>
  items={fruits}
  multiple
  value={value}
  onValueChange={setValue}
  itemToStringLabel={(f) => f.label}
  itemToStringValue={(f) => f.id}
>
  <ComboboxChips>
    <ComboboxValue>
      {value.map((fruit) => (
        <ComboboxChip key={fruit.id} aria-label={fruit.label}>
          {fruit.label}
        </ComboboxChip>
      ))}
    </ComboboxValue>
    <ComboboxChipsInput placeholder="Add fruit…" />
  </ComboboxChips>
  <ComboboxContent>
    <ComboboxEmpty>No matches.</ComboboxEmpty>
    <ComboboxList>
      {(fruit: Fruit) => (
        <ComboboxItem key={fruit.id} value={fruit}>
          {fruit.label}
        </ComboboxItem>
      )}
    </ComboboxList>
  </ComboboxContent>
</Combobox>`,
        preview: <MultiSelect />,
      },
    ]}
  />
);

export default ComboboxPage;
