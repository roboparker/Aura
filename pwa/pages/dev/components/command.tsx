import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
  CommandSeparator,
  CommandShortcut,
} from "@/components/ui/command";
import ComponentDoc from "@/components/dev/ComponentDoc";

const CommandPage = () => (
  <ComponentDoc
    name="Command"
    description="Command palette built on cmdk. Wrap with Dialog (or use CommandDialog) to make it modal."
    importPath={`import { Command, CommandInput, CommandList, CommandEmpty, CommandGroup, CommandItem, CommandSeparator, CommandShortcut } from "@/components/ui/command";`}
    examples={[
      {
        title: "Inline palette",
        code: `<Command className="rounded-lg border shadow-sm">
  <CommandInput placeholder="Type a command…" />
  <CommandList>
    <CommandEmpty>No results.</CommandEmpty>
    <CommandGroup heading="Suggestions">
      <CommandItem>New task <CommandShortcut>⌘N</CommandShortcut></CommandItem>
      <CommandItem>Search projects</CommandItem>
    </CommandGroup>
    <CommandSeparator />
    <CommandGroup heading="Settings">
      <CommandItem>Profile</CommandItem>
      <CommandItem>Preferences</CommandItem>
    </CommandGroup>
  </CommandList>
</Command>`,
        preview: (
          <Command className="w-full max-w-md rounded-lg border shadow-sm">
            <CommandInput placeholder="Type a command…" />
            <CommandList>
              <CommandEmpty>No results.</CommandEmpty>
              <CommandGroup heading="Suggestions">
                <CommandItem>New task <CommandShortcut>⌘N</CommandShortcut></CommandItem>
                <CommandItem>Search projects</CommandItem>
              </CommandGroup>
              <CommandSeparator />
              <CommandGroup heading="Settings">
                <CommandItem>Profile</CommandItem>
                <CommandItem>Preferences</CommandItem>
              </CommandGroup>
            </CommandList>
          </Command>
        ),
      },
    ]}
  />
);

export default CommandPage;
