import Head from "next/head";
import Link from "next/link";

interface QA {
  q: string;
  a: React.ReactNode;
}

interface Section {
  title: string;
  items: QA[];
}

const SECTIONS: Section[] = [
  {
    title: "Getting started",
    items: [
      {
        q: "What is Madori?",
        a: "Madori is a collaborative workspace for tasks, boards, long-form pages, and a shared calendar — with programmatic API access for automation.",
      },
      {
        q: "Is there a free plan?",
        a: (
          <>
            Yes. The Free plan covers unlimited personal work and small shared
            spaces at no cost, with no credit card required. See the{" "}
            <Link href="/pricing" className="underline underline-offset-4 hover:text-foreground">
              pricing page
            </Link>{" "}
            for the full breakdown.
          </>
        ),
      },
      {
        q: "Do I need to verify my email?",
        a: "Yes — after signing up you'll get a confirmation link by email. Clicking it activates your account. You can resend the link from the confirmation screen if it doesn't arrive.",
      },
    ],
  },
  {
    title: "Plans & billing",
    items: [
      {
        q: "What plans are available?",
        a: "Free, Pro, Business, and Enterprise. Free and Pro suit individuals; Business is per-seat for teams that collaborate in shared spaces; Enterprise adds advanced controls and custom terms.",
      },
      {
        q: "How does per-seat billing work?",
        a: "Business is billed per member of your team. Payments are handled securely by Stripe — Madori never stores your card details.",
      },
      {
        q: "Can I cancel anytime?",
        a: "Yes. You can cancel from your billing settings at any time. Your plan stays active until the end of the current billing period, and you won't be charged again.",
      },
      {
        q: "Do you offer refunds?",
        a: (
          <>
            We handle refunds case by case — if something went wrong,{" "}
            <Link href="/feedback" className="underline underline-offset-4 hover:text-foreground">
              get in touch
            </Link>{" "}
            and we'll make it right.
          </>
        ),
      },
      {
        q: "Where do I find my invoices and receipts?",
        a: "We email a receipt after each payment, and you can view or download every invoice from the billing portal in your Madori settings.",
      },
    ],
  },
  {
    title: "Your data & privacy",
    items: [
      {
        q: "Can I export my data?",
        a: "Yes. From Settings → Danger zone you can request a full export of your account data; we email you a secure download link when it's ready.",
      },
      {
        q: "Can I delete my account?",
        a: "Yes. Account deletion is self-service from Settings → Danger zone. It removes your personal data and cancels any active subscription.",
      },
      {
        q: "Is my data private?",
        a: (
          <>
            Content in a space is visible only to that space's members. See our{" "}
            <Link href="/privacy" className="underline underline-offset-4 hover:text-foreground">
              Privacy Policy
            </Link>{" "}
            for how we collect and protect your data.
          </>
        ),
      },
    ],
  },
  {
    title: "Product & platform",
    items: [
      {
        q: "What is the API / MCP access?",
        a: "Madori exposes a Model Context Protocol (MCP) endpoint and a REST API so you can automate your workspace and connect AI tools. Usage is metered per plan.",
      },
      {
        q: "Does it sync with my calendar?",
        a: "Yes — you can connect Google or Microsoft calendars for two-way sync of your dated tasks (available on paid plans).",
      },
      {
        q: "Is Madori open source?",
        a: (
          <>
            Madori is source-available under the{" "}
            <a
              href="https://github.com/roboparker/Aura/blob/main/LICENSE"
              target="_blank"
              rel="noopener noreferrer"
              className="underline underline-offset-4 hover:text-foreground"
            >
              GNU AGPL-3.0
            </a>
            , with commercial licenses available. You're welcome to self-host.
          </>
        ),
      },
    ],
  },
  {
    title: "Support",
    items: [
      {
        q: "How do I get help?",
        a: (
          <>
            Reach us through the{" "}
            <Link href="/feedback" className="underline underline-offset-4 hover:text-foreground">
              feedback form
            </Link>
            , browse the{" "}
            <Link href="/guides" className="underline underline-offset-4 hover:text-foreground">
              guides
            </Link>
            , or open an issue on{" "}
            <a
              href="https://github.com/roboparker/Aura"
              target="_blank"
              rel="noopener noreferrer"
              className="underline underline-offset-4 hover:text-foreground"
            >
              GitHub
            </a>
            .
          </>
        ),
      },
    ],
  },
];

const Faq = () => (
  <>
    <Head>
      <title>FAQ — Madori</title>
      <meta
        name="description"
        content="Frequently asked questions about Madori — plans and billing, your data, the API, and support."
      />
    </Head>

    <div className="bg-background">
      <div className="max-w-3xl mx-auto px-4 py-12 md:py-16">
        <header className="mb-10">
          <h1 className="text-3xl md:text-4xl font-bold tracking-tight text-foreground mb-2">
            Frequently asked questions
          </h1>
          <p className="text-muted-foreground">
            Can&apos;t find what you&apos;re looking for?{" "}
            <Link href="/feedback" className="underline underline-offset-4 hover:text-foreground">
              Get in touch
            </Link>
            .
          </p>
        </header>

        <div className="space-y-10">
          {SECTIONS.map((section) => (
            <section key={section.title}>
              <h2 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground mb-4">
                {section.title}
              </h2>
              <div className="space-y-6">
                {section.items.map((item) => (
                  <div key={item.q}>
                    <h3 className="font-semibold text-foreground mb-1.5">{item.q}</h3>
                    <p className="text-muted-foreground leading-relaxed">{item.a}</p>
                  </div>
                ))}
              </div>
            </section>
          ))}
        </div>
      </div>
    </div>
  </>
);

export default Faq;
