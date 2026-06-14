import ImpersonationBanner from "@/components/common/ImpersonationBanner";
import ComponentDoc from "@/components/dev/ComponentDoc";

const ImpersonationBannerPage = () => (
  <ComponentDoc
    name="ImpersonationBanner"
    description="Full-width amber bar shown above the top bar whenever an admin is impersonating another user (the firewall's switch_user). It keeps the operator aware they're acting as someone else and offers a one-click way back to their own session via AuthContext.stopImpersonation(). Takes no props — it reads `user.impersonator` from AuthContext and renders nothing (zero layout cost) in the normal case. Mounted once in Layout; the UserMenu carries the same 'Stop impersonation' control for when the bar scrolls out of view."
    importPath={`import ImpersonationBanner from "@/components/common/ImpersonationBanner";`}
    examples={[
      {
        title: "Default",
        code: `<ImpersonationBanner />`,
        preview: (
          <p className="text-sm text-muted-foreground">
            Renders nothing unless the signed-in session is an admin
            impersonating another user (<code className="mx-1">user.impersonator</code>{" "}
            is set). When active it shows a full-width amber bar reading
            &ldquo;Impersonating {"{name}"}&rdquo; with a{" "}
            <strong>Stop impersonation</strong> button.
          </p>
        ),
      },
    ]}
  />
);

void ImpersonationBanner;

export default ImpersonationBannerPage;
