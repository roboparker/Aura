import Breadcrumbs from "@/components/common/Breadcrumbs";
import ComponentDoc from "@/components/dev/ComponentDoc";

const BreadcrumbsPage = () => (
  <ComponentDoc
    name="Breadcrumbs"
    description="URL-derived breadcrumb trail for the navbar. Top-level segments come from a static label registry; UUID segments are treated as entity ids — the previous segment names the resource and the display name is lazy-fetched (`/{resource}/{id}`) and cached via react-query. The last crumb is the current page and isn't a link. Renders nothing on the home page and auth screens (it stands in for the Aura wordmark on every other authenticated route). Only prop is `className`."
    importPath={`import Breadcrumbs from "@/components/common/Breadcrumbs";`}
    examples={[
      {
        title: "Derived from the current path",
        description:
          "Rendered live below — the trail reflects this page's own URL (Home / Dev / Components / Breadcrumbs).",
        code: `<Breadcrumbs className="hidden md:flex flex-1 min-w-0" />`,
        preview: <Breadcrumbs />,
      },
    ]}
  />
);

export default BreadcrumbsPage;
