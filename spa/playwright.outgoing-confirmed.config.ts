import { defineConfig } from '@playwright/test';
import real from './playwright.real.config';

export default defineConfig({
  ...real,
  testDir: './e2e',
  testMatch: 'outgoing-confirmed-count.spec.ts',
  timeout: 180_000,
  reporter: [['list']],
});
