import { defineConfig, devices } from '@playwright/test';

/**
 * Playwright config for Ogami ERP SPA E2E tests.
 *
 * Target: local dev server (API proxied through Vite on port 5173 by default,
 * or through Nginx on port 80 in Docker). Tests run against the SPA served
 * by Vite's dev server.
 *
 * API calls are intercepted via `page.route()` — no backend needed.
 */
export default defineConfig({
  testDir: './e2e',
  fullyParallel: false,
  retries: 1,
  workers: 1,
  reporter: [['html', { outputFolder: 'playwright-report' }]],
  use: {
    baseURL: 'http://localhost:5173',
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
});
