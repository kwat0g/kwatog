import path from 'node:path';
import { mkdir } from 'node:fs/promises';
import type { Page, TestInfo } from '@playwright/test';

function safeName(value: string): string {
  return value.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 90) || 'step';
}

export async function captureAnnotatedStep(
  page: Page,
  actor: string,
  stepName: string,
  sequence: number,
  testInfo: TestInfo,
): Promise<string> {
  const id = `e2e-step-annotation-${sequence}`;
  const localTime = new Intl.DateTimeFormat('en-PH', {
    dateStyle: 'medium',
    timeStyle: 'medium',
    timeZone: 'Asia/Manila',
  }).format(new Date());

  await page.evaluate(({ elementId, title, role, timestamp }) => {
    const banner = document.createElement('aside');
    banner.id = elementId;
    banner.textContent = `${title} · ${role} · ${timestamp} (Asia/Manila)`;
    banner.setAttribute('aria-hidden', 'true');
    banner.style.cssText = 'position:fixed;z-index:2147483647;top:0;left:0;right:0;padding:10px 16px;background:#102a43;color:#fff;font:600 14px/1.4 system-ui,sans-serif;box-shadow:0 2px 8px #0005;pointer-events:none';
    document.body.appendChild(banner);
  }, { elementId: id, title: stepName, role: actor, timestamp: localTime });

  const file = testInfo.outputPath('walkthrough', `${String(sequence).padStart(3, '0')}-${safeName(stepName)}.png`);
  await mkdir(path.dirname(file), { recursive: true });
  await page.screenshot({ path: file, fullPage: false, animations: 'disabled' });
  await page.locator(`#${id}`).evaluate((element) => element.remove());
  return path.relative(testInfo.outputDir, file);
}
