import { defineConfig, devices } from '@playwright/test';
import { assertE2EEnvironment } from '../scripts/e2e/environment-guard.mjs';

// Apply the same fail-closed environment check used by the managed E2E runner.
// This config is sometimes invoked directly, so a URL-only check is not enough.
const { baseURL: E2E_BASE_URL } = assertE2EEnvironment(process.env);

/**
 * REAL-backend E2E config (mission harness, Phase 1).
 *
 * Unlike playwright.config.ts (which mocks every API call via page.route),
 * this config exercises the actual UI + actual API + actual database together:
 * E2E_BASE_URL is required and must point to the isolated test app, which
 * serves the SPA and proxies /api/v1 to Laravel. Zero mocks — any page.route() call
 * in a spec under e2e-real/ is a harness violation.
 *
 * Run only inside the isolated E2E environment with its guarded identifiers
 * and loopback HTTPS endpoint (https://127.0.0.1:18443 or https://localhost:18443).
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
    // The disposable E2E Nginx endpoint uses a generated self-signed TLS cert.
    // Match the process E2E config so both browser pages and page.request can use it.
    ignoreHTTPSErrors: true,
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
