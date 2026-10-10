import { test, expect, type Page } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { loginAs, statefulCsrfHeaders, statefulHeaders } from './helpers';

const fixture = JSON.parse(readFileSync(process.env.E2E_FINISH_FIRST_MANIFEST ?? '../api/storage/app/finish-first-e2e.json', 'utf8'));
if (!String(fixture.tag).startsWith('ff-')) throw new Error('A disposable finish-first fixture is required.');
async function api(page: Page, method: string, path: string, data?: unknown) {
  const response = await page.request.fetch(`/api/v1${path}`, { method, data, headers: await statefulCsrfHeaders(page) });
  expect(response.ok(), await response.text()).toBe(true);
  return (await response.json()).data;
}
test('entire production precedes final QC, with independent release and Warehouse visibility', async ({ browser }) => {
  const pages: Record<string, Page> = {};
  const contexts = [];
  try {
    for (const role of ['manager', 'recorder', 'qc', 'checker', 'warehouse']) {
      const context = await browser.newContext(); contexts.push(context);
      pages[role] = await context.newPage();
      await loginAs(pages[role], fixture.accounts[role].email, 'Finish-First-Test-2026!');
    }
    const path = `/production/work-orders/${fixture.wo}`;
    const manager = pages.manager;
    await manager.goto(path);
    await manager.getByRole('button', { name: 'Start', exact: true }).click();
    const dialog = manager.getByRole('dialog');
    const presence = dialog.getByRole('checkbox');
    await expect(dialog.getByRole('button', { name: 'Start production', exact: true })).toBeVisible();
    for (const item of await presence.all()) await item.check();
    const starting = manager.waitForResponse(response => response.url().endsWith(`${fixture.wo}/start`) && response.request().method() === 'POST', { timeout: 20_000 });
    await dialog.getByRole('button', { name: 'Start production', exact: true }).click();
    expect((await starting).ok()).toBe(true);
    const before = await api(pages.qc, 'GET', `/quality/work-orders/${fixture.wo}/final-inspection`);
    expect(before.batches).toHaveLength(0);
    await pages.recorder.goto(`/self-service/work-orders/${fixture.wo}`);
    await pages.recorder.getByRole('spinbutton', { name: 'Quantity made' }).fill('200');
    const output = pages.recorder.waitForResponse(response => response.url().includes(`/production/my-work-orders/${fixture.wo}/outputs`) && response.request().method() === 'POST', { timeout: 20_000 });
    await expect(pages.recorder.getByRole('button', { name: 'Record production', exact: true })).toBeEnabled();
    await pages.recorder.getByRole('button', { name: 'Record production', exact: true }).click();
    expect((await output).ok()).toBe(true);
    expect((await api(pages.qc, 'GET', `/quality/work-orders/${fixture.wo}/final-inspection`)).batches).toHaveLength(0);
    const heldBefore = await pages.warehouse.request.get(`/api/v1/inventory/stock-levels?item_id=${fixture.item}`, { headers: statefulHeaders() });
    expect(heldBefore.ok()).toBe(true);
    const heldRows = (await heldBefore.json()).data;
    expect(heldRows.reduce((sum: number, row: { quality_held_quantity: string }) => sum + Number(row.quality_held_quantity), 0)).toBe(200);
    await manager.goto(path);
    await manager.getByRole('button', { name: 'Finish production', exact: true }).click();
    const finishing = manager.waitForResponse(response => response.url().endsWith(`${fixture.wo}/complete`) && response.request().method() === 'POST', { timeout: 20_000 });
    await manager.getByRole('dialog').getByRole('button', { name: 'Finish production', exact: true }).click();
    expect((await finishing).ok()).toBe(true);
    const qcPath = `/quality/work-orders/${fixture.wo}/final-inspection`;
    await pages.qc.goto(qcPath);
    await pages.qc.getByRole('button', { name: 'PASS ALL', exact: true }).click();
    await pages.qc.getByRole('checkbox', { name: /I checked all 200 pieces|I checked every piece against all listed requirements/ }).check();
    const submitting = pages.qc.waitForResponse(response => response.url().endsWith('/final-inspection/submit'));
    await pages.qc.getByRole('button', { name: 'Submit inspection', exact: true }).click();
    expect((await submitting).ok()).toBe(true);
    await expect(pages.qc.getByRole('button', { name: 'Approve inspection results' })).toHaveCount(0);
    await pages.checker.goto(qcPath);
    const reviewing = pages.checker.waitForResponse(response => response.url().endsWith('/final-inspection/review'));
    await pages.checker.getByRole('button', { name: 'Approve inspection results' }).click();
    expect((await reviewing).ok()).toBe(true);
    const released = await api(pages.checker, 'GET', `/quality/work-orders/${fixture.wo}/final-inspection`);
    expect(released.state).toBe('released'); expect(released.accepted_quantity).toBe(200);
    await pages.warehouse.goto('/inventory/stock-levels');
    const stock = await pages.warehouse.request.get(`/api/v1/inventory/stock-levels?item_id=${fixture.item}`, { headers: statefulHeaders() });
    expect(stock.ok()).toBe(true);
    const rows = (await stock.json()).data;
    expect(rows.reduce((sum: number, row: { usable_quantity: string }) => sum + Number(row.usable_quantity), 0)).toBe(200);
    expect(rows.reduce((sum: number, row: { quality_held_quantity: string }) => sum + Number(row.quality_held_quantity), 0)).toBe(0);
    for (const [role, page] of Object.entries(pages)) await page.screenshot({ path: test.info().outputPath(`${role}.png`), fullPage: true });
  } finally { for (const context of contexts) await context.close().catch(() => {}); }
});
