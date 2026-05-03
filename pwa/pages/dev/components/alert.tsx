import { Terminal, AlertCircle } from "lucide-react";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import ComponentDoc from "@/components/dev/ComponentDoc";

const AlertPage = () => (
  <ComponentDoc
    name="Alert"
    description="Inline message panel for notices and errors. Composed from Alert, AlertTitle, and AlertDescription."
    importPath={`import { Alert, AlertTitle, AlertDescription } from "@/components/ui/alert";`}
    examples={[
      {
        title: "Default",
        code: `<Alert>
  <Terminal className="h-4 w-4" />
  <AlertTitle>Heads up!</AlertTitle>
  <AlertDescription>You can save changes with ⌘+S at any time.</AlertDescription>
</Alert>`,
        preview: (
          <Alert>
            <Terminal className="h-4 w-4" />
            <AlertTitle>Heads up!</AlertTitle>
            <AlertDescription>You can save changes with ⌘+S at any time.</AlertDescription>
          </Alert>
        ),
      },
      {
        title: "Destructive",
        description: "Use for errors and irreversible warnings.",
        code: `<Alert variant="destructive">
  <AlertCircle className="h-4 w-4" />
  <AlertTitle>Could not save</AlertTitle>
  <AlertDescription>The server returned a 500. Please retry.</AlertDescription>
</Alert>`,
        preview: (
          <Alert variant="destructive">
            <AlertCircle className="h-4 w-4" />
            <AlertTitle>Could not save</AlertTitle>
            <AlertDescription>The server returned a 500. Please retry.</AlertDescription>
          </Alert>
        ),
      },
    ]}
  />
);

export default AlertPage;
