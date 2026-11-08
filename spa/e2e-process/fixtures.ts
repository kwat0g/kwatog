import { test as base, expect } from '@playwright/test';
import { monitorPage, type PageDiagnostics } from './helpers/page-diagnostics';

type ProcessFixtures = { diagnostics: PageDiagnostics };

export const test = base.extend<ProcessFixtures>({
  diagnostics: [async ({ page }, use) => {
    const diagnostics = await monitorPage(page);
    await use(diagnostics);
    diagnostics.assertClean();
  }, { auto: true }],
});

export { expect };
