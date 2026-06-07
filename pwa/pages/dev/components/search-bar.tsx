import SearchBar from "@/components/common/SearchBar";
import ComponentDoc from "@/components/dev/ComponentDoc";

const SearchBarPage = () => (
  <ComponentDoc
    name="SearchBar"
    description="Always-visible task search input mounted in the navbar with a debounced live autocomplete dropdown (max 6 hits). Submitting Enter or clicking 'View all results' navigates to /search; clicking a single result jumps there with the result's IRI in the hash so the page can scroll/highlight it in context. ⌘/Ctrl+K is owned by the SearchOverlay command palette (not this bar). Pre-fills from ?q= and the legacy ?search= so deep links feel continuous."
    importPath={`import SearchBar from "@/components/common/SearchBar";`}
    examples={[
      {
        title: "Default (in navbar)",
        code: `<SearchBar className="flex-1 min-w-0 max-w-3xl" />`,
        preview: (
          <p className="text-sm text-muted-foreground">
            Mounted by <code className="mx-1">Navbar</code> for authenticated
            users. The bar is already visible at the top of this page; press{" "}
            <kbd className="rounded border bg-muted px-1.5 py-0.5 text-xs">
              ⌘K
            </kbd>{" "}
            to open the SearchOverlay palette.
          </p>
        ),
      },
    ]}
  />
);

void SearchBar;

export default SearchBarPage;
