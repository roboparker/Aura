import CustomFieldsManager from "@/components/custom-fields/CustomFieldsManager";
import ComponentDoc from "@/components/dev/ComponentDoc";

const CustomFieldsManagerPage = () => (
  <ComponentDoc
    name="CustomFieldsManager"
    description="Per-project custom field definition manager. Lists every CFD on the project, with an inline composer for create / edit and a delete action. Owner-only mutations — for non-owners the list is read-only. Mounted at /projects/[id]/custom-fields."
    importPath={`import CustomFieldsManager from "@/components/custom-fields/CustomFieldsManager";`}
    examples={[
      {
        title: "Live manager (read-only demo)",
        code: `<CustomFieldsManager
  projectIri="/projects/<uuid>"
  isProjectOwner={true}
/>`,
        // Rendering the live manager here would issue a network call
        // against a placeholder IRI and show a load error — keep the
        // example as a code snippet only. The component is exercised
        // end-to-end via /projects/[id]/custom-fields and the
        // custom-fields Playwright spec.
        preview: (
          <p className="text-sm text-muted-foreground">
            Mounted on the project custom-fields page. The dropdown type
            reveals an options editor; non-dropdown types hide it. Names are
            unique within a project (server-enforced via UniqueEntity).
          </p>
        ),
      },
    ]}
  />
);

// Reference the import so the bundle keeps the component graph honest even
// though the demo is static.
void CustomFieldsManager;

export default CustomFieldsManagerPage;
