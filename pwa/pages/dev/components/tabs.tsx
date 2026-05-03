import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import ComponentDoc from "@/components/dev/ComponentDoc";

const TabsPage = () => (
  <ComponentDoc
    name="Tabs"
    description={`Tab list and panels. Pass variant="line" to TabsList for an underline style. Set orientation on Tabs to flip vertical.`}
    importPath={`import { Tabs, TabsList, TabsTrigger, TabsContent } from "@/components/ui/tabs";`}
    examples={[
      {
        title: "Default",
        code: `<Tabs defaultValue="overview">
  <TabsList>
    <TabsTrigger value="overview">Overview</TabsTrigger>
    <TabsTrigger value="activity">Activity</TabsTrigger>
    <TabsTrigger value="settings">Settings</TabsTrigger>
  </TabsList>
  <TabsContent value="overview">High-level summary.</TabsContent>
  <TabsContent value="activity">Recent events.</TabsContent>
  <TabsContent value="settings">Configuration.</TabsContent>
</Tabs>`,
        preview: (
          <Tabs defaultValue="overview" className="w-full max-w-md">
            <TabsList>
              <TabsTrigger value="overview">Overview</TabsTrigger>
              <TabsTrigger value="activity">Activity</TabsTrigger>
              <TabsTrigger value="settings">Settings</TabsTrigger>
            </TabsList>
            <TabsContent value="overview">High-level summary.</TabsContent>
            <TabsContent value="activity">Recent events.</TabsContent>
            <TabsContent value="settings">Configuration.</TabsContent>
          </Tabs>
        ),
      },
      {
        title: "Underline variant",
        code: `<Tabs defaultValue="all">
  <TabsList variant="line">
    <TabsTrigger value="all">All</TabsTrigger>
    <TabsTrigger value="open">Open</TabsTrigger>
    <TabsTrigger value="done">Done</TabsTrigger>
  </TabsList>
  <TabsContent value="all">Everything.</TabsContent>
  <TabsContent value="open">In flight.</TabsContent>
  <TabsContent value="done">Completed.</TabsContent>
</Tabs>`,
        preview: (
          <Tabs defaultValue="all" className="w-full max-w-md">
            <TabsList variant="line">
              <TabsTrigger value="all">All</TabsTrigger>
              <TabsTrigger value="open">Open</TabsTrigger>
              <TabsTrigger value="done">Done</TabsTrigger>
            </TabsList>
            <TabsContent value="all">Everything.</TabsContent>
            <TabsContent value="open">In flight.</TabsContent>
            <TabsContent value="done">Completed.</TabsContent>
          </Tabs>
        ),
      },
    ]}
  />
);

export default TabsPage;
