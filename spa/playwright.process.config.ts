import { defineConfig, devices } from '@playwright/test';

const baseURL = process.env.E2E_BASE_URL;
const browserLaunchOptions = {
  args: ['--disable-dev-shm-usage', '--no-sandbox', '--ignore-certificate-errors'],
};

export default defineConfig({
  testDir: './e2e-process',
  testMatch: ['**/*.process.spec.ts', '**/*.floor.spec.ts', '**/*.platform.spec.ts', '**/*.setup.ts'],
  outputDir: './test-results/process',
  forbidOnly: true,
  fullyParallel: false,
  workers: 1,
  retries: 0,
  timeout: 90_000,
  expect: { timeout: 8_000 },
  globalSetup: './e2e-process/global-setup.ts',
  reporter: [
    ['list'],
    ['html', { outputFolder: './playwright-report/process', open: 'never' }],
    ['json', { outputFile: './test-results/process-results.json' }],
  ],
  use: {
    baseURL,
    headless: true,
    ignoreHTTPSErrors: true,
    actionTimeout: 15_000,
    navigationTimeout: 30_000,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'off',
    launchOptions: browserLaunchOptions,
  },
  projects: [
    {
      name: 'auth-setup',
      testMatch: '**/*.setup.ts',
      // Login traces record filled input values. Keep the failure screenshot,
      // but do not persist the disposable E2E password in a trace artifact.
      use: { ...devices['Desktop Chrome'], launchOptions: browserLaunchOptions, ignoreHTTPSErrors: true, trace: 'off' },
    },
    {
      name: 'chromium-process',
      testMatch: '**/*.process.spec.ts',
      dependencies: ['auth-setup'],
      use: { ...devices['Desktop Chrome'], launchOptions: browserLaunchOptions, ignoreHTTPSErrors: true },
    },
    {
      name: 'tablet-floor',
      testMatch: '**/*.floor.spec.ts',
      dependencies: ['auth-setup'],
      // Use Chromium with tablet/touch emulation. Playwright's iPad descriptor
      // selects WebKit, which violates the Chromium-only E2E runtime contract.
      use: {
        ...devices['Desktop Chrome'],
        browserName: 'chromium',
        viewport: { width: 810, height: 1080 },
        isMobile: true,
        hasTouch: true,
        deviceScaleFactor: 2,
        launchOptions: browserLaunchOptions,
        ignoreHTTPSErrors: true,
      },
    },
    {
      name: 'platform-checks',
      testMatch: '**/*.platform.spec.ts',
      dependencies: ['auth-setup'],
      use: { ...devices['Desktop Chrome'], launchOptions: browserLaunchOptions, ignoreHTTPSErrors: true },
    },
  ],
});
