import Footer from "@/components/common/Footer";
import ComponentDoc from "@/components/dev/ComponentDoc";

const FooterPage = () => (
  <ComponentDoc
    name="Footer"
    description="Static site footer rendered at the bottom of the app shell by Layout. Three link columns — Product (Home, Guides, Privacy, Terms), Developers (API reference, API guide, Architecture, Component library), and Project (GitHub, Report an issue) — over a MIT-license line. No props."
    importPath={`import Footer from "@/components/common/Footer";`}
    examples={[
      {
        title: "Default",
        code: `<Footer />`,
        preview: <Footer />,
      },
    ]}
  />
);

export default FooterPage;
