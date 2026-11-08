import { readFile } from 'node:fs/promises';
import path from 'node:path';
import type { Page } from '@playwright/test';

type IssueKind = 'console' | 'pageerror' | 'requestfailed' | 'http5xx';
type NoiseAllowlist = Record<IssueKind, string[]>;
type Issue = { kind: IssueKind; evidence: string };

export class PageDiagnostics {
  private readonly issues: Issue[] = [];
  private readonly expectedConsoleErrors: string[] = [];

  constructor(private readonly allowlist: NoiseAllowlist) {}

  record(kind: IssueKind, evidence: string): void {
    const cleanEvidence = evidence.replace(/[?&](?:token|password|secret|authorization)=[^&\s]*/gi, '[redacted]');
    if (kind === 'console') {
      const expectedIndex = this.expectedConsoleErrors.findIndex((fragment) => cleanEvidence.includes(fragment));
      if (expectedIndex !== -1) {
        this.expectedConsoleErrors.splice(expectedIndex, 1);
        return;
      }
    }
    if (this.allowlist[kind].some((known) => cleanEvidence.includes(known))) return;
    this.issues.push({ kind, evidence: cleanEvidence });
  }

  expectConsoleErrorOnceContaining(fragment: string): void {
    this.expectedConsoleErrors.push(fragment);
  }

  assertClean(stepName = 'current process step'): void {
    if (this.issues.length === 0) return;
    const details = this.issues.map(({ kind, evidence }) => `- ${kind}: ${evidence}`).join('\n');
    throw new Error(`Unexpected browser/network errors during ${stepName}:\n${details}`);
  }
}

async function loadAllowlist(): Promise<NoiseAllowlist> {
  const file = path.resolve('e2e-process/known-network-noise.json');
  const parsed = JSON.parse(await readFile(file, 'utf8')) as Partial<NoiseAllowlist>;
  return {
    console: parsed.console ?? [],
    pageerror: parsed.pageerror ?? [],
    requestfailed: parsed.requestfailed ?? [],
    http5xx: parsed.http5xx ?? [],
  };
}

export async function monitorPage(page: Page): Promise<PageDiagnostics> {
  const diagnostics = new PageDiagnostics(await loadAllowlist());
  page.on('console', (message) => {
    if (message.type() !== 'error') return;
    let location = '';
    try {
      const url = new URL(message.location().url);
      location = ` at ${url.origin}${url.pathname}`;
    } catch {
      // Some browser-generated messages have no source URL.
    }
    diagnostics.record('console', `${message.text().slice(0, 500)}${location}`);
  });
  page.on('pageerror', (error) => diagnostics.record('pageerror', error.message.slice(0, 500)));
  page.on('requestfailed', (request) => {
    let location = request.url();
    try {
      const url = new URL(request.url());
      location = `${url.origin}${url.pathname}`;
    } catch {
      // Keep a non-URL request string only when the browser cannot parse it.
    }
    diagnostics.record('requestfailed', `${request.method()} ${location}: ${request.failure()?.errorText ?? 'request failed'}`);
  });
  page.on('response', (response) => {
    if (response.status() < 500) return;
    const url = new URL(response.url());
    diagnostics.record('http5xx', `${response.status()} ${url.origin}${url.pathname}`);
  });
  return diagnostics;
}
