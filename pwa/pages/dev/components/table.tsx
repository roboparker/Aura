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
    description="Styled wrappers for the native HTML table primitives."
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
    ]}
  />
);

export default TablePage;
