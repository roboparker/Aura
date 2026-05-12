import Layout from "@/components/common/Layout";
import ComponentDoc from "@/components/dev/ComponentDoc";

const LayoutPage = () => (
  <ComponentDoc
    name="Layout"
    description="App-shell wrapper used by _app.tsx. Mounts ThemeProvider, QueryClientProvider, HydrationBoundary, AuthProvider, and ActiveSpaceProvider, then renders the persistent Navbar + Sidebar around page content. Sidebar collapses to nothing for unauthenticated visitors so marketing and auth screens keep their full-width chrome."
    importPath={`import Layout from "@/components/common/Layout";`}
    examples={[
      {
        title: "Wrapping the app",
        code: `// pages/_app.tsx
<Layout dehydratedState={pageProps.dehydratedState}>
  <Component {...pageProps} />
</Layout>`,
        preview: (
          <p className="text-sm text-muted-foreground">
            Mounted once at the top of <code className="mx-1">_app.tsx</code>.
            Previewing live here would nest a second Layout (with its own
            providers, navbar, and sidebar) inside the existing one — keep the
            example as a code snippet only.
          </p>
        ),
      },
    ]}
  />
);

void Layout;

export default LayoutPage;
