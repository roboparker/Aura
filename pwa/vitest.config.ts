import { fileURLToPath } from "node:url";
import { defineConfig } from "vitest/config";

// Unit tests for the pure logic under pwa/lib (query parsing, validation
// mapping, password-strength estimation, …). Component/integration coverage
// stays in the Playwright e2e suite; this is the fast unit layer.
export default defineConfig({
  test: {
    environment: "node",
    include: ["lib/**/*.test.ts"],
  },
  resolve: {
    alias: {
      "@": fileURLToPath(new URL(".", import.meta.url)),
    },
  },
});
