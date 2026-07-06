import { Button } from "@/components/ui/button";
import {
  Popover,
  PopoverContent,
  PopoverDescription,
  PopoverHeader,
  PopoverTitle,
  PopoverTrigger,
} from "@/components/ui/popover";
import ComponentDoc from "@/components/dev/ComponentDoc";

const PopoverPage = () => (
  <ComponentDoc
    name="Popover"
    description="Floating panel anchored to a trigger. Built on radix-ui. Reach for Dialog when the panel is modal."
    importPath={`import { Popover, PopoverTrigger, PopoverContent, PopoverHeader, PopoverTitle, PopoverDescription } from "@/components/ui/popover";`}
    examples={[
      {
        title: "Anchored info panel",
        code: `<Popover>
  <PopoverTrigger asChild>
    <Button variant="outline">Show details</Button>
  </PopoverTrigger>
  <PopoverContent align="start">
    <PopoverHeader>
      <PopoverTitle>Board moonshot</PopoverTitle>
      <PopoverDescription>Owned by ada-lovelace.</PopoverDescription>
    </PopoverHeader>
  </PopoverContent>
</Popover>`,
        preview: (
          <Popover>
            <PopoverTrigger asChild>
              <Button variant="outline">Show details</Button>
            </PopoverTrigger>
            <PopoverContent align="start">
              <PopoverHeader>
                <PopoverTitle>Board moonshot</PopoverTitle>
                <PopoverDescription>Owned by ada-lovelace.</PopoverDescription>
              </PopoverHeader>
            </PopoverContent>
          </Popover>
        ),
      },
    ]}
  />
);

export default PopoverPage;
