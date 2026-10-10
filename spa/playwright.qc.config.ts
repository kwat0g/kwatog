import { defineConfig } from '@playwright/test';
import real from './playwright.real.config';

export default defineConfig({
  ...real,
  testMatch: 'work-order-qc-connection.spec.ts',
  timeout: 180_000,
  outputDir: 'wo-qc-test-results',
  reporter: [['list'], ['json', { outputFile: 'e2e-real/results/wo-qc-summary.json' }]],
});
