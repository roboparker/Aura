import Head from "next/head";
import Link from "next/link";

const LAST_UPDATED = "June 9, 2026";

const Privacy = () => (
  <>
    <Head>
      <title>Privacy Policy — Madori</title>
      <meta
        name="description"
        content="How Madori collects, uses, and protects your personal data."
      />
    </Head>

    <main className="bg-background">
      <div className="max-w-3xl mx-auto px-4 py-12 md:py-16">
        <header className="mb-10">
          <h1 className="text-3xl md:text-4xl font-bold tracking-tight text-foreground mb-2">
            Privacy Policy
          </h1>
          <p className="text-sm text-muted-foreground">
            Last updated: {LAST_UPDATED}
          </p>
        </header>

        <div className="space-y-8 text-foreground leading-relaxed">
          <section>
            <p className="text-muted-foreground">
              This Privacy Policy describes how Madori (the &quot;Service&quot;),
              operated by Robert Parker (&quot;we&quot;, &quot;us&quot;), collects,
              uses, and protects your personal data when you use it. By using
              the Service, you agree to the practices described here.
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold mb-2">1. Information we collect</h2>
            <ul className="list-disc pl-6 space-y-2 text-muted-foreground">
              <li>
                <strong className="text-foreground">Account information.</strong>{" "}
                Your email address, display name, and an optional avatar image
                you upload.
              </li>
              <li>
                <strong className="text-foreground">Content you create.</strong>{" "}
                Tasks, projects, pages, discussions, comments, attachments,
                tags, and other content you submit to the Service.
              </li>
              <li>
                <strong className="text-foreground">Authentication data.</strong>{" "}
                A hashed password and, if you enable two-factor authentication,
                an encrypted TOTP secret and one-time backup codes. If you
                create API tokens, we store them in hashed form.
              </li>
              <li>
                <strong className="text-foreground">Push subscriptions.</strong>{" "}
                If you enable push notifications, we store a per-device
                subscription endpoint so we can deliver notifications to that
                device.
              </li>
              <li>
                <strong className="text-foreground">Usage data.</strong>{" "}
                Basic technical information such as IP address, browser type,
                and timestamps recorded in server logs for security and
                operational purposes.
              </li>
            </ul>
          </section>

          <section>
            <h2 className="text-xl font-semibold mb-2">2. How we use your information</h2>
            <p className="text-muted-foreground mb-2">We use your data to:</p>
            <ul className="list-disc pl-6 space-y-1 text-muted-foreground">
              <li>Provide, maintain, and improve the Service.</li>
              <li>Authenticate you and keep your account secure.</li>
              <li>Send transactional emails (sign-up confirmation, password reset, invites, notifications).</li>
              <li>Respond to your requests, questions, and reports.</li>
              <li>Detect, prevent, and address fraud, abuse, and technical issues.</li>
            </ul>
          </section>

          <section>
            <h2 className="text-xl font-semibold mb-2">3. Sharing</h2>
            <p className="text-muted-foreground">
              We do not sell your personal data. Content you create is visible
              only to you and the collaborators you share it with (for example,
              members of a space). We may share data with infrastructure
              providers strictly to operate the Service — for example, push
              notifications are delivered through your browser vendor&apos;s
              push service (such as those operated by Google, Mozilla, or
              Apple) — and we may disclose information if required by law.
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold mb-2">4. Data retention</h2>
            <p className="text-muted-foreground">
              We retain your account data and content for as long as your
              account is active. When you delete your account, we delete your
              personal data — your profile, credentials, sessions, tokens, and
              notifications — within a reasonable period, except where
              retention is required for legal, security, or fraud-prevention
              purposes. Content you contributed to shared spaces (such as
              tasks, pages, discussions, comments, and attachments) may remain
              available to the other members of those spaces, disassociated
              from your identity.
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold mb-2">5. Security</h2>
            <p className="text-muted-foreground">
              We use industry-standard measures to protect your data — including
              hashed passwords, encrypted two-factor secrets, secure transport
              (HTTPS), and access controls. No system is perfectly secure, so
              we cannot guarantee absolute security.
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold mb-2">6. Your rights</h2>
            <p className="text-muted-foreground mb-2">
              Depending on your location, you may have the right to:
            </p>
            <ul className="list-disc pl-6 space-y-1 text-muted-foreground">
              <li>Access the personal data we hold about you.</li>
              <li>Correct inaccurate or incomplete data.</li>
              <li>Delete your account and associated data.</li>
              <li>Export your data in a portable format.</li>
              <li>Object to or restrict certain processing.</li>
            </ul>
            <p className="text-muted-foreground mt-2">
              You can exercise most of these rights directly from your account
              settings. For anything else, contact us using the details below.
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold mb-2">7. Cookies and local storage</h2>
            <p className="text-muted-foreground">
              We use cookies and browser local storage for essential
              functionality such as keeping you signed in and remembering your
              active space, theme preferences, and recent searches (stored
              only on your device). We do not use third-party advertising or
              tracking cookies.
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold mb-2">8. Children</h2>
            <p className="text-muted-foreground">
              The Service is not intended for children under the age of 13 (or
              the equivalent minimum age in your jurisdiction). We do not
              knowingly collect personal data from children.
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold mb-2">9. Changes to this policy</h2>
            <p className="text-muted-foreground">
              We may update this Privacy Policy from time to time. When we do,
              we will revise the &quot;Last updated&quot; date above. If the
              changes are material, we will make reasonable efforts to notify
              you.
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold mb-2">10. Contact</h2>
            <p className="text-muted-foreground">
              Questions about this policy or your data? Reach out via the
              project&apos;s{" "}
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
                href="/terms"
                className="text-primary underline-offset-4 hover:underline"
              >
                Terms and Conditions
              </Link>
              .
            </p>
          </section>
        </div>
      </div>
    </main>
  </>
);

export default Privacy;
