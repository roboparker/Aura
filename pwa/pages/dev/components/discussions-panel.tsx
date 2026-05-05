import DiscussionsPanel from "@/components/discussions/DiscussionsPanel";
import ComponentDoc from "@/components/dev/ComponentDoc";

const DiscussionsPanelPage = () => (
  <ComponentDoc
    name="DiscussionsPanel"
    description="Project-level discussion board: list, filter by category, create, edit, delete, pin/lock. Mounts inside the project detail page's tab. Talks to /discussions directly so the parent page only has to pass the project IRI and the current user's permissions."
    importPath={`import DiscussionsPanel from "@/components/discussions/DiscussionsPanel";`}
    examples={[
      {
        title: "Live panel (read-only demo)",
        code: `<DiscussionsPanel
  projectIri="/projects/<uuid>"
  currentUserIri="/users/<uuid>"
  isProjectOwner={false}
/>`,
        // Rendering the live panel here would issue a network call against
        // a placeholder IRI and show a load error — keep the example as a
        // code snippet only. The component is exercised end-to-end via the
        // /projects/[id] page and the discussions Playwright spec.
        preview: (
          <p className="text-sm text-muted-foreground">
            Mounted on the project detail page under the Discussions tab. The
            panel only renders for an authenticated project member.
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
