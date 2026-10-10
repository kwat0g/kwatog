import { defineConfig } from '@playwright/test';
import real from './playwright.real.config';
export default defineConfig({ ...real, testMatch: 'finish-first-wo-qc.spec.ts', timeout: 180_000,
  outputDir: 'finish-first-test-results', reporter: [['list'], ['json', { outputFile: 'e2e-real/results/finish-first-summary.json' }]] });
