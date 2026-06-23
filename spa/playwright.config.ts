import { defineConfig, devices } from '@playwright/test';

/**
 * OGAMI ERP SPA — E2E test config.
 *
 * Mocks ALL backend API calls via `page.route()`. No backend container needed.
 * Tests run exclusively against the Vite dev server's rendered DOM.
 *
 * Two project tiers:
 * 1. desktop-chromium — full suite (RBAC, chain workflows, hardening)
 * 2. mobile-chromium     — self-service portal at 390×844
 *
 * Run:
 *   npm run test:e2e          # headless chromium
 *   npm run test:e2e:ui       # Playwright UI mode
 */
export default defineConfig({
  testDir: './e2e',
  fullyParallel: true,
  retries: process.env.CI ? 2 : 0,
  workers: process.env.CI ? 4 : undefined,
  timeout: 30_000,
  expect: { timeout: 10_000 },
  reporter: [
    ['html', { outputFolder: 'playwright-report' }],
    ['list'],
  ],
  use: {
    baseURL: 'http://localhost:5173',
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },
  webServer: {
    command: 'npx vite --port 5173 --strictPort',
    url: 'http://localhost:5173',
    reuseExistingServer: true,
    timeout: 30_000,
  },
  projects: [
    {
      name: 'desktop-chromium',
      testIgnore: '**/mobile/**',
      use: { ...devices['Desktop Chrome'] },
    },
    {
      name: 'desktop-firefox',
      testIgnore: '**/mobile/**',
      use: { ...devices['Desktop Firefox'] },
      retries: 1,
    },
    {
      name: 'mobile-chromium',
      testMatch: '**/mobile/**',
      use: { ...devices['Pixel 7'] },
    },
  ],
});
