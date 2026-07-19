import CustomFieldsManager from "@/components/custom-fields/CustomFieldsManager";
import ComponentDoc from "@/components/dev/ComponentDoc";

const CustomFieldsManagerPage = () => (
  <ComponentDoc
    name="CustomFieldsManager"
    description="Per-board custom field definition manager. Kind-aware (#227): each CFD is a (kind, subtype, config) triple — boolean, text.{text,rich_text,url}, numeric.{int,float,money}, date.{date,time,datetime}, select.{single,multi}, reference.{user,task,board,page}. Renders the fields as a drag-to-reorder table (NAME · KIND · REQUIRED · FOOTER · FILLED) with a right-side CustomFieldSheet drawer for create / edit / delete and a board-scoped change-log drawer. Per-kind config editors (money preview, live URL pattern check, date-range pickers, select options with colors + usage counts, reference target cards) live in kind-editors.tsx. Space-admin only mutations — for non-admins the table is read-only. Mounted at /boards/[id]/custom-fields."
    importPath={`import CustomFieldsManager from "@/components/custom-fields/CustomFieldsManager";`}
    examples={[
      {
        title: "Live manager (read-only demo)",
        code: `<CustomFieldsManager
  boardIri="/boards/<uuid>"
  boardTitle="Spring Collection Launch"
  spaceName="Acme Marketing"
  isSpaceAdmin={true}
/>`,
        // Rendering the live manager here would issue a network call
        // against a placeholder IRI and show a load error — keep the
        // example as a code snippet only. The component is exercised
        // end-to-end via /boards/[id]/custom-fields and the
        // custom-fields Playwright spec.
        preview: (
          <p className="text-sm text-muted-foreground">
            Mounted on the board custom-fields page. Picking a kind in the
            composer swaps in the per-kind config editor (currency picker for
            money, min/max for numeric, options list for select, length /
            pattern for text, …). Names are unique within a board
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
