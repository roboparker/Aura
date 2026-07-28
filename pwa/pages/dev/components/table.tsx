import {
  Table,
  TableBody,
  TableCaption,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import ComponentDoc from "@/components/dev/ComponentDoc";

const rows = [
  { id: "T-101", title: "Polish onboarding", owner: "ada", status: "Open" },
  { id: "T-102", title: "Fix avatar fallback", owner: "lin", status: "Done" },
  { id: "T-103", title: "Audit Mercure topics", owner: "ren", status: "Open" },
];

const TablePage = () => (
  <ComponentDoc
    name="Table"
    description="Styled wrappers for the native HTML table primitives. When the table is wider than its container it becomes a keyboard-focusable scroll region and shows an edge shadow — both applied only while it actually overflows."
    importPath={`import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell, TableCaption } from "@/components/ui/table";`}
    examples={[
      {
        title: "Basic data table",
        code: `<Table>
  <TableCaption>Open and recently closed tasks.</TableCaption>
  <TableHeader>
    <TableRow>
      <TableHead>ID</TableHead>
      <TableHead>Title</TableHead>
      <TableHead>Owner</TableHead>
      <TableHead>Status</TableHead>
    </TableRow>
  </TableHeader>
  <TableBody>
    {rows.map((row) => (
      <TableRow key={row.id}>
        <TableCell>{row.id}</TableCell>
        <TableCell>{row.title}</TableCell>
        <TableCell>{row.owner}</TableCell>
        <TableCell>{row.status}</TableCell>
      </TableRow>
    ))}
  </TableBody>
</Table>`,
        preview: (
          <Table>
            <TableCaption>Open and recently closed tasks.</TableCaption>
            <TableHeader>
              <TableRow>
                <TableHead>ID</TableHead>
                <TableHead>Title</TableHead>
                <TableHead>Owner</TableHead>
                <TableHead>Status</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {rows.map((row) => (
                <TableRow key={row.id}>
                  <TableCell>{row.id}</TableCell>
                  <TableCell>{row.title}</TableCell>
                  <TableCell>{row.owner}</TableCell>
                  <TableCell>{row.status}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        ),
      },
      {
        title: "Horizontal overflow",
        code: `{/* Nothing to opt into: when the table outgrows its container it
    gains tabindex=0 + role="region" so a keyboard user can scroll it,
    plus a right-edge shadow that retracts at the end.

    The keyboard part is the point. An overflow-x-auto div scrolls by
    wheel and touch but is not focusable, so keyboard-only users could
    not reach off-screen columns at all (WCAG 2.1.1).

    Both are conditional on actually overflowing — an unconditional
    tabindex would put a dead tab stop on every table that fits.

    Override the region's name when a page has several tables: */}
<Table scrollRegionLabel="Invoice line items">…</Table>`,
        preview: (
          <div className="max-w-sm">
            <Table scrollRegionLabel="Example task table">
              <TableHeader>
                <TableRow>
                  <TableHead>ID</TableHead>
                  <TableHead>Title</TableHead>
                  <TableHead>Owner</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead>Assignees</TableHead>
                  <TableHead>Actions</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {rows.map((row) => (
                  <TableRow key={row.id}>
                    <TableCell>{row.id}</TableCell>
                    <TableCell>{row.title}</TableCell>
                    <TableCell>{row.owner}</TableCell>
                    <TableCell>{row.status}</TableCell>
                    <TableCell>ada, lin</TableCell>
                    <TableCell>Edit</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
            <p className="mt-2 text-sm text-muted-foreground">
              Tab to the table above, then use the arrow keys. Constrained to{" "}
              <code>max-w-sm</code> here so it overflows; the first example on
              this page fits and so stays out of the tab order entirely.
            </p>
          </div>
        ),
      },
    ]}
  />
);

export default TablePage;
