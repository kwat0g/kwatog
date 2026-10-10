import { defineConfig, devices } from '@playwright/test';

const E2E_BASE_URL = process.env.E2E_BASE_URL;
if (!E2E_BASE_URL) throw new Error('Set E2E_BASE_URL to the isolated test application.');

/**
 * REAL-backend E2E config (mission harness, Phase 1).
 *
 * Unlike playwright.config.ts (which mocks every API call via page.route),
 * this config exercises the actual UI + actual API + actual database together:
 * E2E_BASE_URL is required and must point to the isolated test app, which
 * serves the SPA and proxies /api/v1 to Laravel. Zero mocks — any page.route() call
 * in a spec under e2e-real/ is a harness violation.
 *
 * Run (headless, per mission):
 *   E2E_BASE_URL=http://localhost:18080 npx playwright test -c playwright.real.config.ts --project=real-chromium
 *
 * Notes:
 * - Auth throttle is 5 logins/min/IP, so specs log in via the API helper with
 *   automatic 429 backoff (see e2e-real/helpers.ts) instead of hammering UI login.
 * - Only ONE role session per worker; workers:1 to stay under rate limits and
 *   to avoid two agents sharing one database.
 */
export default defineConfig({
  testDir: './e2e-real',
  fullyParallel: false,
  workers: 1,
  timeout: 90_000,
  expect: { timeout: 15_000 },
  retries: 0,
  reporter: [
    ['list'],
    ['json', { outputFile: 'e2e-real/results/summary.json' }],
  ],
  use: {
    baseURL: E2E_BASE_URL,
    headless: true,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'off',
  },
  projects: [
    {
      name: 'real-chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
});
