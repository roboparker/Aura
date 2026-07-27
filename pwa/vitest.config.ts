import { fileURLToPath } from "node:url";
import { defineConfig } from "vitest/config";

// Unit tests for the pure logic under pwa/lib (query parsing, validation
// mapping, password-strength estimation, …). Component/integration coverage
// stays in the Playwright e2e suite; this is the fast unit layer.
//
// Coverage gate (the PR floor): `pnpm test:coverage` enforces the thresholds
// below over an explicit allowlist of pure-logic modules — the modules we've
// committed to keeping unit-tested. When you add a new framework-free helper
// under lib/, add it (and its `*.test.ts`) to `coverage.include` so the gate
// keeps it honest. Hooks (`use*.ts`), API clients, and type-only modules are
// intentionally out of scope here — their coverage lives in the e2e suite.
export default defineConfig({
  test: {
    environment: "node",
    include: ["lib/**/*.test.ts"],
    coverage: {
      provider: "v8",
      reporter: ["text", "text-summary"],
      include: [
        "lib/authRedirect.ts",
        "lib/avatarPalette.ts",
        "lib/contrast.ts",
        "lib/currencies.ts",
        "lib/landingDestination.ts",
        "lib/notificationPrefs.ts",
        "lib/notificationTypes.ts",
        "lib/passwordStrength.ts",
        "lib/relativeTime.ts",
        "lib/searchQuery.ts",
        "lib/userDisplay.ts",
        "lib/violations.ts",
      ],
      thresholds: {
        lines: 85,
        statements: 85,
        functions: 90,
        branches: 75,
      },
    },
  },
  resolve: {
    alias: {
      "@": fileURLToPath(new URL(".", import.meta.url)),
    },
  },
});
