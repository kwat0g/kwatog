import { readFile } from 'node:fs/promises';
import path from 'node:path';
import type { Browser, BrowserContext, BrowserContextOptions, Page } from '@playwright/test';
import { monitorPage, type PageDiagnostics } from './helpers/page-diagnostics';

export type E2EActor = {
  actor: string;
  realm: 'internal' | 'customer' | 'supplier';
  email: string;
  role?: string;
};

export async function loadActors(): Promise<E2EActor[]> {
  const file = process.env.E2E_ACCOUNT_MANIFEST;
  if (!file) throw new Error('E2E_ACCOUNT_MANIFEST is not set.');
  const manifest = JSON.parse(await readFile(file, 'utf8')) as { accounts?: E2EActor[] };
  if (!Array.isArray(manifest.accounts)) throw new Error('E2E actor manifest has no accounts array.');
  return manifest.accounts;
}

export async function actorContext(
  browser: Browser,
  actor: string,
  options: BrowserContextOptions = {},
): Promise<BrowserContext> {
  const safeActor = actor.replace(/[^a-zA-Z0-9_-]/g, '_');
  const storageState = path.resolve('.auth', `${safeActor}.json`);
  return browser.newContext({
    storageState,
    baseURL: process.env.E2E_BASE_URL,
    ignoreHTTPSErrors: true,
    ...options,
  });
}

export async function actorPage(
  browser: Browser,
  actor: string,
  options: BrowserContextOptions = {},
): Promise<{ context: BrowserContext; page: Page; diagnostics: PageDiagnostics }> {
  const context = await actorContext(browser, actor, options);
  const page = await context.newPage();
  const diagnostics = await monitorPage(page);
  return { context, page, diagnostics };
}
