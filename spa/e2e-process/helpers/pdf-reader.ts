import { execFile } from 'node:child_process';
import { mkdtemp, rm, writeFile } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { promisify } from 'node:util';
import type { Download } from '@playwright/test';

const execFileAsync = promisify(execFile);

export async function readDownloadedPdf(download: Download): Promise<string> {
  const filePath = await download.path();
  if (!filePath) throw new Error('The downloaded PDF is not available in the browser container.');
  return readPdfFile(filePath);
}

export async function readPdfFile(filePath: string): Promise<string> {
  const { stdout } = await execFileAsync('pdftotext', ['-layout', filePath, '-'], {
    timeout: 15_000,
    maxBuffer: 4 * 1024 * 1024,
  });
  return stdout;
}

/** Save a PDF response caused by a real UI download action, then extract its text. */
export async function readPdfBytes(bytes: Buffer): Promise<string> {
  const directory = await mkdtemp(path.join(os.tmpdir(), 'ogami-e2e-pdf-'));
  const filePath = path.join(directory, 'document.pdf');
  try {
    await writeFile(filePath, bytes, { mode: 0o600 });
    return await readPdfFile(filePath);
  } finally {
    await rm(directory, { recursive: true, force: true });
  }
}

export function expectPdfContains(source: string, expectedValues: string[]): void {
  const normalized = source.replace(/\s+/g, ' ').trim();
  for (const value of expectedValues) {
    if (!normalized.includes(value)) throw new Error(`PDF text did not contain expected value: ${value}`);
  }
}
