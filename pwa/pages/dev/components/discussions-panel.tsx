import DiscussionsPanel from "@/components/discussions/DiscussionsPanel";
import ComponentDoc from "@/components/dev/ComponentDoc";

const DiscussionsPanelPage = () => (
  <ComponentDoc
    name="DiscussionsPanel"
    description="Project-level discussion list: filter by category, post a new thread, pin/lock/delete inline. Each row's title links to the dedicated detail page at /projects/{id}/discussions/{discussionId}, where the body, edit form, and moderation controls live. Used by /projects/[id]/discussions."
    importPath={`import DiscussionsPanel from "@/components/discussions/DiscussionsPanel";`}
    examples={[
      {
        title: "Live panel (read-only demo)",
        code: `<DiscussionsPanel
  projectId="<uuid>"
  projectIri="/projects/<uuid>"
  currentUserIri="/users/<uuid>"
  isProjectOwner={false}
/>`,
        // Rendering the live panel here would issue a network call against
        // a placeholder IRI and show a load error — keep the example as a
        // code snippet only. The component is exercised end-to-end via
        // /projects/[id]/discussions and the Playwright spec.
        preview: (
          <p className="text-sm text-muted-foreground">
            Mounted on the project discussions list page. The panel only renders
            for an authenticated project member. Row titles route to
            <code className="mx-1">/projects/[id]/discussions/[discussionId]</code>
            for the full body + edit / moderation controls.
          </p>
        ),
      },
    ]}
  />
);

// Reference the import so the bundle keeps the component graph honest even
// though the demo is static.
void DiscussionsPanel;

export default DiscussionsPanelPage;
