import { defineConfig, devices } from '@playwright/test';

/** Live-browser configuration for the real Docker/Nginx stack. */
export default defineConfig({
  testDir: './e2e',
  testMatch: '**/live-procurement.spec.ts',
  fullyParallel: false,
  workers: 1,
  retries: 0,
  timeout: 90_000,
  expect: { timeout: 15_000 },
  reporter: [['list'], ['html', { outputFolder: 'playwright-live-report' }]],
  use: {
    baseURL: 'http://localhost',
    headless: true,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },
  projects: [
    {
      name: 'live-chromium',
      use: {
        ...devices['Desktop Chrome'],
        launchOptions: {
          args: ['--disable-dev-shm-usage', '--no-sandbox'],
        },
      },
    },
  ],
});
