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
    baseURL: 'http://127.0.0.1:5174',
    headless: true,
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },
  /*
   * Visual baselines are committed (`e2e/__screenshots__/`) and compared by
   * `e2e/gallery.spec.ts`. Playwright's default template appends the browser
   * and platform (`…-firefox-linux`), which would fork the baseline set per
   * developer machine and mean CI quietly creating a new file instead of
   * comparing against the reviewed one. One file per shot, on purpose: the
   * baselines are generated on the same Firefox/Ubuntu the CI job runs, and an
   * update is a reviewable diff rather than a silent re-baseline.
   */
  snapshotPathTemplate: '{testDir}/__screenshots__/{testFilePath}/{arg}{ext}',
  webServer: {
    command: 'npx vite --host 127.0.0.1 --port 5174 --strictPort',
    env: {
      VITE_HMR_HOST: '127.0.0.1',
      VITE_HMR_PORT: '5174',
      VITE_DISABLE_API_PROXY: 'true',
      VITE_REVERB_APP_KEY: 'ogami_reverb',
      VITE_REVERB_HOST: '127.0.0.1',
      VITE_REVERB_PORT: '8080',
      VITE_REVERB_SCHEME: 'http',
    },
    url: 'http://127.0.0.1:5174',
    reuseExistingServer: false,
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
