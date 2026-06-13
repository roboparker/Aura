import Link from "next/link";

const Footer = () => (
  <footer className="mt-12 border-t bg-background">
    <div className="mx-auto max-w-6xl px-4 py-8">
      <div className="grid grid-cols-1 gap-6 sm:grid-cols-3">
        <div>
          <h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
            Product
          </h3>
          <ul className="space-y-1.5 text-sm">
            <li>
              <Link href="/" className="text-foreground hover:underline">
                Home
              </Link>
            </li>
            <li>
              <Link
                href="/guides"
                className="text-foreground hover:underline"
              >
                Guides
              </Link>
            </li>
            <li>
              <Link
                href="/privacy"
                className="text-foreground hover:underline"
              >
                Privacy
              </Link>
            </li>
            <li>
              <Link
                href="/terms"
                className="text-foreground hover:underline"
              >
                Terms
              </Link>
            </li>
          </ul>
        </div>
        <div>
          <h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
            Developers
          </h3>
          <ul className="space-y-1.5 text-sm">
            <li>
              <a href="/docs" className="text-foreground hover:underline">
                API reference
              </a>
            </li>
            <li>
              <Link
                href="/guides/developer/api-guide"
                className="text-foreground hover:underline"
              >
                API guide
              </Link>
            </li>
            <li>
              <Link
                href="/guides/developer/architecture"
                className="text-foreground hover:underline"
              >
                Architecture
              </Link>
            </li>
            <li>
              <Link
                href="/dev/components"
                className="text-foreground hover:underline"
              >
                Component library
              </Link>
            </li>
          </ul>
        </div>
        <div className="sm:col-span-1">
          <h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
            Project
          </h3>
          <ul className="space-y-1.5 text-sm">
            <li>
              <a
                href="https://github.com/roboparker/Aura"
                target="_blank"
                rel="noopener noreferrer"
                className="text-foreground hover:underline"
              >
                GitHub
              </a>
            </li>
            <li>
              <a
                href="https://github.com/roboparker/Aura/issues"
                target="_blank"
                rel="noopener noreferrer"
                className="text-foreground hover:underline"
              >
                Report an issue
              </a>
            </li>
          </ul>
        </div>
      </div>
      <p className="mt-8 text-xs text-muted-foreground">
        Madori — source-available under the{" "}
        <a
          href="https://github.com/roboparker/Aura/blob/main/LICENSE"
          target="_blank"
          rel="noopener noreferrer"
          className="underline underline-offset-4 hover:text-foreground"
        >
          GNU AGPL-3.0
        </a>
        .
      </p>
    </div>
  </footer>
);

export default Footer;
