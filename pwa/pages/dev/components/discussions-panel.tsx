import DiscussionsPanel from "@/components/discussions/DiscussionsPanel";
import ComponentDoc from "@/components/dev/ComponentDoc";

const DiscussionsPanelPage = () => (
  <ComponentDoc
    name="DiscussionsPanel"
    description="Space-level discussion list: filter by category, post a new thread, pin/lock/delete inline. Each row's title links to the dedicated detail page at /discussions/{id}, where the body, edit form, and moderation controls live. Used by /discussions and the space detail's Discussions tab."
    importPath={`import DiscussionsPanel from "@/components/discussions/DiscussionsPanel";`}
    examples={[
      {
        title: "Live panel (read-only demo)",
        code: `<DiscussionsPanel
  spaceIri="/spaces/<uuid>"
  currentUserIri="/users/<uuid>"
  isSpaceAdmin={false}
/>`,
        preview: (
          <p className="text-sm text-muted-foreground">
            Mounted on the discussions list page. The panel only renders
            for an authenticated space member. Row titles route to
            <code className="mx-1">/discussions/[id]</code>
            for the full body + edit / moderation controls.
          </p>
        ),
      },
    ]}
  />
);

void DiscussionsPanel;

export default DiscussionsPanelPage;
