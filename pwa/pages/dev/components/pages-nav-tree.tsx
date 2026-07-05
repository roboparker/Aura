import PagesNavTree from "@/components/common/PagesNavTree";
import ComponentDoc from "@/components/dev/ComponentDoc";

const PagesNavTreePage = () => (
  <ComponentDoc
    name="PagesNavTree"
    description="Sidebar navigator for a space's pages, rendered inside the Pages section of SidebarNav. Fetches the active space's pages and shows them as a nested, collapsible tree with drag-to-reorder and drag-to-nest via @dnd-kit (the canonical flattened-tree boardion: drag vertically to reorder siblings, horizontally to nest under the row above / outdent). A dragged row's grip is the drag handle; parents get a collapse chevron. Drops persist through plain PATCH /pages/{id} ({parent, position}) — the API's author/admin edit gate applies, so a rejected move reverts by refetching. Tree helpers (buildTree / flattenTree / getProjection / applyMove) live in @/lib/pageTree."
    importPath={`import PagesNavTree from "@/components/common/PagesNavTree";`}
    examples={[
      {
        title: "Inside the Pages section",
        description:
          "Wired up by SidebarNav's ContentSection when section.resource === 'pages'. Reads from ActiveSpaceContext and react-query; renders nothing meaningful without a signed-in session and an active space.",
        code: `<PagesNavTree
  spaceIri={activeSpace["@id"]}
  currentPath={router.asPath.split("?")[0]}
  wrap={wrap}
  addLink={addLink}
/>`,
        preview: (
          <p className="text-sm text-muted-foreground">
            Visible in the real left sidebar under{" "}
            <strong>Pages</strong> — drag a page by its grip to reorder, or drag
            it right to nest it under the page above.
          </p>
        ),
      },
    ]}
  />
);

void PagesNavTree;

export default PagesNavTreePage;
