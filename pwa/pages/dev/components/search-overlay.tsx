import SearchOverlay from "@/components/search/SearchOverlay";
import ComponentDoc from "@/components/dev/ComponentDoc";

const SearchOverlayPage = () => (
  <ComponentDoc
    name="SearchOverlay"
    description="⌘K command palette mounted once in the navbar. Owns the global ⌘/Ctrl+K binding and renders itself (null until opened). Shows 'Try' suggestion chips (Overdue tasks, Announcements this week, @me), recent searches (localStorage via @/lib/recentSearches), and the active-space scope chip, with ↑↓/↵ keyboard navigation. Submitting routes to /search?q=…. The inline navbar SearchBar keeps its type-in-place autocomplete; ⌘K opens this richer palette instead."
    importPath={`import SearchOverlay from "@/components/search/SearchOverlay";`}
    examples={[
      {
        title: "Usage (read-only — mounted in the navbar)",
        code: `// In the navbar, once, for authenticated viewers:
<SearchOverlay />

// Opened from anywhere with ⌘K / Ctrl+K. Self-contained: no props.`,
        preview: (
          <p className="text-sm text-muted-foreground">
            The overlay is a navbar singleton that depends on auth + active-space
            context and registers a global ⌘K listener, so it isn&apos;t mounted
            live here. Press
            <kbd className="mx-1 rounded border border-input bg-muted px-1.5 text-xs">⌘K</kbd>
            anywhere in the app to open it.
          </p>
        ),
      },
    ]}
  />
);

void SearchOverlay;

export default SearchOverlayPage;
