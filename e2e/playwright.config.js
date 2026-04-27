// @ts-check
const { defineConfig, devices } = require('@playwright/test');

module.exports = defineConfig({
  testDir: './tests',
  // The PWA runs `next dev` in CI and compiles routes on first hit. Warm
  // them serially before workers start so cold compilation doesn't stall a
  // parallel test's 5s `toHaveURL` assertion.
  globalSetup: require.resolve('./global-setup.js'),
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  // Retry once in CI to absorb rare flakes in pointer/keyboard drag tests.
  retries: process.env.CI ? 1 : 0,
  // Parallelise in CI — each test creates its own user with a unique email, so
  // workers don't contend on data. Keep a single worker locally to make
  // interactive debugging predictable.
  workers: process.env.CI ? 4 : 1,
  reporter: 'html',
  use: {
    ignoreHTTPSErrors: true,
    trace: 'on-first-retry',
  },

  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'], },
    },
    {
      name: 'firefox',
      use: { ...devices['Desktop Firefox'] },
    },
    {
      name: 'webkit',
      use: { ...devices['Desktop Safari'] },
    },
  ],
});

