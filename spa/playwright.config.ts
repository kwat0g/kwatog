import { defineConfig, devices } from '@playwright/test';

/**
 * OGAMI ERP SPA — E2E test config.
 *
 * Mocks ALL backend API calls via `page.route()`. No backend container needed.
 * Tests run exclusively against the Vite dev server's rendered DOM.
 *
 * Firefox is the default browser for the low-memory audit pass. Set
 * PW_BROWSER=chromium to run the Chromium-specific fallback project.
 *
 * Run:
 *   npm run test:e2e          # headless Firefox, one worker
 *   PW_BROWSER=chromium npm run test:e2e
 *   npm run test:e2e:ui       # Playwright UI mode
 */
export default defineConfig({
  testDir: './e2e',
  fullyParallel: false,
  retries: process.env.CI ? 2 : 0,
  workers: 1,
  timeout: 30_000,
  expect: { timeout: 10_000 },
  reporter: [
    ['html', { outputFolder: 'playwright-report' }],
    ['list'],
  ],
  use: {
    baseURL: 'http://localhost:5173',
    headless: true,
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
  projects: process.env.PW_BROWSER === 'chromium'
    ? [
        {
          name: 'desktop-chromium',
          testIgnore: '**/mobile/**',
          use: {
            ...devices['Desktop Chrome'],
            launchOptions: {
              args: [
                '--disable-gpu',
                '--disable-dev-shm-usage',
                '--disable-extensions',
                '--disable-background-networking',
                '--no-sandbox',
                '--js-flags=--max-old-space-size=512',
              ],
            },
          },
        },
        {
          name: 'mobile-chromium',
          testMatch: '**/mobile/**',
          use: {
            ...devices['Pixel 7'],
            launchOptions: {
              args: ['--disable-dev-shm-usage', '--no-sandbox'],
            },
          },
        },
      ]
    : [
        {
          name: 'desktop-firefox',
          testIgnore: '**/mobile/**',
          use: { ...devices['Desktop Firefox'] },
          retries: 1,
        },
        {
          name: 'mobile-firefox',
          testMatch: '**/mobile/**',
          // Firefox does not support Playwright's Chromium-only isMobile
          // context option. Keep the phone viewport and touch behavior while
          // using a desktop Firefox context.
          use: {
            ...devices['Desktop Firefox'],
            viewport: { width: 390, height: 844 },
            isMobile: false,
            hasTouch: true,
          },
        },
      ],
});
