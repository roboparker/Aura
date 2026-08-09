import Head from "next/head";
import Link from "next/link";
import { pageTitle } from "@/lib/pageTitle";

const LAST_UPDATED = "June 9, 2026";

const Terms = () => (
  <>
    <Head>
      <title>{pageTitle("Terms and Conditions")}</title>
      <meta
        name="description"
        content="The terms and conditions that govern your use of Madori."
      />
    </Head>

    <div className="bg-background">
      <div className="max-w-3xl mx-auto px-4 py-12 md:py-16">
        <header className="mb-10">
          <h1 className="text-3xl md:text-4xl font-bold tracking-tight text-foreground mb-2">
            Terms and Conditions
          </h1>
          <p className="text-sm text-muted-foreground">
            Last updated: {LAST_UPDATED}
          </p>
        </header>

        <div className="space-y-8 text-foreground leading-relaxed">
          <section>
            <p className="text-muted-foreground">
              These Terms and Conditions (&quot;Terms&quot;) govern your access
              to and use of Madori (the &quot;Service&quot;), operated by Robert
              Parker (&quot;we&quot;, &quot;us&quot;). By creating an account or
              using the Service, you agree to be bound by these Terms. If you
              do not agree, do not use the Service.
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold mb-2">1. Your account</h2>
            <p className="text-muted-foreground">
              You are responsible for safeguarding the credentials used to
              access your account and for any activity that occurs under it.
              You must provide accurate information when registering and keep
              it current. We may suspend or terminate accounts that violate
              these Terms.
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold mb-2">2. Acceptable use</h2>
            <p className="text-muted-foreground mb-2">
              You agree not to:
            </p>
            <ul className="list-disc pl-6 space-y-1 text-muted-foreground">
              <li>Use the Service for any unlawful purpose or in violation of any applicable law.</li>
              <li>Upload, post, or transmit content that is harmful, abusive, defamatory, or infringing.</li>
              <li>Attempt to gain unauthorised access to the Service or any related systems.</li>
              <li>Interfere with, disrupt, or place an unreasonable load on the Service.</li>
              <li>Reverse engineer, decompile, or otherwise attempt to derive source code, except where permitted by law.</li>
            </ul>
          </section>

          <section>
            <h2 className="text-xl font-semibold mb-2">3. Your content</h2>
            <p className="text-muted-foreground">
              You retain ownership of the content you create, upload, or
              share through the Service (&quot;Your Content&quot;). By using
              the Service, you grant us a limited, non-exclusive license to
              host, store, and display Your Content solely for the purpose of
              operating and providing the Service to you and your collaborators.
              You are responsible for Your Content and for ensuring you have
              the right to share it.
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold mb-2">4. Service availability</h2>
            <p className="text-muted-foreground">
              We aim to keep the Service available and reliable, but we do
              not guarantee uninterrupted access. The Service is provided on
              an &quot;as is&quot; and &quot;as available&quot; basis without
              warranties of any kind, whether express or implied.
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold mb-2">5. Termination</h2>
            <p className="text-muted-foreground">
              You may stop using the Service at any time and may delete your
              account from your settings. Deletion takes effect immediately but
              is reversible for 30 days — see the{" "}
              <Link
                href="/privacy"
                className="underline underline-offset-4 hover:text-foreground"
              >
                Privacy Policy
              </Link>{" "}
              for what we retain and for how long. We may suspend or terminate
              your access if you breach these Terms or if we are required to do
              so by law, and may remove content or accounts that violate them.
              Provisions that by their nature should survive termination will do
              so.
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold mb-2">6. Limitation of liability</h2>
            <p className="text-muted-foreground">
              To the maximum extent permitted by law, we will not be liable
              for any indirect, incidental, special, consequential, or
              punitive damages, or any loss of profits or revenues, whether
              incurred directly or indirectly, arising from your use of the
              Service.
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold mb-2">7. Governing law</h2>
            <p className="text-muted-foreground">
              These Terms are governed by the laws of the State of Oregon,
              United States, without regard to its conflict-of-law provisions.
              Any dispute arising out of or relating to these Terms or the
              Service will be brought exclusively in the state or federal
              courts located in Oregon, and you consent to the jurisdiction of
              those courts. Nothing in this section limits any rights you may
              have under the mandatory consumer-protection laws of the place
              where you live.
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold mb-2">8. Changes to these Terms</h2>
            <p className="text-muted-foreground">
              We may update these Terms from time to time. When we do, we
              will revise the &quot;Last updated&quot; date above. If the
              changes are material, we will make reasonable efforts to notify
              you. Your continued use of the Service after an update
              constitutes acceptance of the revised Terms.
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold mb-2">9. Contact</h2>
            <p className="text-muted-foreground">
              Questions about these Terms? Reach out via the board&apos;s{" "}
              <Link
                href="https://github.com/roboparker/Aura"
                target="_blank"
                rel="noopener noreferrer"
                className="text-primary underline-offset-4 hover:underline"
              >
                GitHub repository
              </Link>
              .
            </p>
          </section>

          <section className="pt-4 border-t">
            <p className="text-sm text-muted-foreground">
              See also our{" "}
              <Link
                href="/privacy"
                className="text-primary underline-offset-4 hover:underline"
              >
                Privacy Policy
              </Link>
              .
            </p>
          </section>
        </div>
      </div>
    </div>
  </>
);

export default Terms;
