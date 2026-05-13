import CustomFieldsManager from "@/components/custom-fields/CustomFieldsManager";
import ComponentDoc from "@/components/dev/ComponentDoc";

const CustomFieldsManagerPage = () => (
  <ComponentDoc
    name="CustomFieldsManager"
    description="Per-project custom field definition manager. Kind-aware (#227): each CFD is a (kind, subtype, config) triple — boolean, text.{text,rich_text,url}, numeric.{int,float,money}, date.{date,time,datetime}, select.{single,multi}, reference.{user,task,page,discussion}. Per-kind config editors live in kind-editors.tsx. Inline composer for create / edit and a delete action. Space-admin only mutations — for non-admins the list is read-only. Mounted at /projects/[id]/custom-fields."
    importPath={`import CustomFieldsManager from "@/components/custom-fields/CustomFieldsManager";`}
    examples={[
      {
        title: "Live manager (read-only demo)",
        code: `<CustomFieldsManager
  projectIri="/projects/<uuid>"
  isSpaceAdmin={true}
/>`,
        // Rendering the live manager here would issue a network call
        // against a placeholder IRI and show a load error — keep the
        // example as a code snippet only. The component is exercised
        // end-to-end via /projects/[id]/custom-fields and the
        // custom-fields Playwright spec.
        preview: (
          <p className="text-sm text-muted-foreground">
            Mounted on the project custom-fields page. Picking a kind in the
            composer swaps in the per-kind config editor (currency picker for
            money, min/max for numeric, options list for select, length /
            pattern for text, …). Names are unique within a project
            (server-enforced via UniqueEntity).
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
