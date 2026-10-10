import { test, expect } from '@playwright/test';
import { readFileSync, mkdirSync } from 'node:fs';
import { baseUrl, loginAs, statefulHeaders, statefulCsrfHeaders } from './helpers';

if (baseUrl() !== 'http://localhost:18080') throw new Error('Checklist transactions require the isolated audit application.');

const fixturePath = process.env.QC_CHECKLIST_FIXTURE;
if (!fixturePath) throw new Error('QC_CHECKLIST_FIXTURE must identify isolated seeded records.');
const fixture = JSON.parse(readFileSync(fixturePath, 'utf8')) as {
  accounts: Record<string, string>; inspections: Record<string, string>; spec: string; item: string;
};
const evidence = process.env.QC_CHECKLIST_EVIDENCE ?? '/tmp/qc-pass-fail-20261006/browser';
mkdirSync(evidence, { recursive: true });

test('all five stages use real PASS/FAIL capture with independent review', async ({ page, browser }) => {
  test.setTimeout(180_000);
  await loginAs(page, fixture.accounts.maker, 'password');
  const errors: string[] = [];
  page.on('pageerror', error => errors.push(error.message));
  for (const [stage, id] of Object.entries(fixture.inspections)) {
    await page.goto(`/quality/inspections/${id}`);
    const panel = page.getByRole('region', { name: 'Inspection checklist', exact: true });
    await expect(panel.getByRole('button', { name: /^PASS (Surface condition|Fit check|Identification)$/ })).toHaveCount(3);
    if (stage === 'incoming') {
      await expect(panel.getByRole('spinbutton')).toHaveCount(0);
      await expect(panel.getByText(/Planned sample coverage:\s*20\s*pieces/)).toBeVisible();
      await expect(panel.getByText(/AQL F: accept up to 1 defective piece; reject at 2 or more/)).toBeVisible();
    } else {
      await expect(panel.getByLabel('Actual size for Fit check')).toBeVisible();
      await expect(panel.getByText(/Target: 10.0000 mm/)).toBeVisible();
      await expect(panel.getByText(/Lowest allowed: 9.9000 mm/)).toBeVisible();
    }
    await expect(panel.getByText('Fits the approved inspection gauge.', { exact: true })).toBeVisible();
    for (const width of [1440, 390]) {
      await page.setViewportSize({ width, height: 1000 });
      await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
      for (const button of await panel.getByRole('button', { name: /^PASS (Surface condition|Fit check|Identification)$/ }).all()) {
        const box = await button.boundingBox();
        expect(box!.height).toBeGreaterThanOrEqual(44);
        expect(box!.width).toBeGreaterThanOrEqual(44);
      }
      await page.screenshot({ path: `${evidence}/${stage}-${width}.png`, fullPage: true });
    }
    await page.setViewportSize({ width: 1440, height: 1000 });
    if (stage !== 'incoming') await panel.getByLabel('Actual size for Fit check').fill('10.0000');
    for (const button of await panel.getByRole('button', { name: /^PASS (Surface condition|Fit check|Identification)$/ }).all()) await button.click();
    await panel.getByRole('checkbox', { name: /I confirm (all required checks on the planned sample were completed|every piece was inspected against all required checks)/ }).check();
    const responsePromise = page.waitForResponse(response => response.url().endsWith(`/inspections/${id}/batch-result`) && response.request().method() === 'POST');
    await panel.getByRole('button', { name: 'Submit inspection', exact: true }).click();
    const response = await responsePromise;
    expect(response.status(), await response.text()).toBe(200);
    const saved = (await response.json()).data;
    expect(saved.capture_method).toBe('checklist');
    expect(saved.result_version).toBe(1);
    expect(saved.recorded_result).toBe('passed');
    if (stage === 'incoming') expect(saved.measurements.every((row: { measured_value: unknown }) => row.measured_value === null)).toBe(true);
    else {
      const fitCheck = saved.measurements.find((row: { parameter_name: string }) => row.parameter_name === 'Fit check');
      expect(fitCheck.measured_value).toBe('10.0000');
      expect(saved.measurements.filter((row: { parameter_name: string }) => row.parameter_name !== 'Fit check').every((row: { measured_value: unknown }) => row.measured_value === null)).toBe(true);
    }
    const headers = await statefulCsrfHeaders(page);
    if (['incoming', 'outgoing', 'customer_return', 'supplier_return'].includes(stage)) {
      expect(saved.status).toBe('awaiting_review');
      const selfReview = await page.request.patch(`${baseUrl()}/api/v1/quality/inspections/${id}/review`, { headers, data: { decision: 'passed', expected_result_version: 1 } });
      expect(selfReview.status()).toBe(403);
    } else expect(saved.status).toBe('passed');
    const overwrite = await page.request.post(`${baseUrl()}/api/v1/quality/inspections/${id}/batch-result`, { headers, data: JSON.parse(response.request().postData()!) });
    expect(overwrite.status()).toBe(422);
  }
  const checkerContext = await browser.newContext();
  const checker = await checkerContext.newPage();
  await loginAs(checker, fixture.accounts.checker, 'password');
  for (const stage of ['incoming', 'outgoing', 'customer_return', 'supplier_return']) {
    const id = fixture.inspections[stage];
    await checker.goto(`/quality/inspections/${id}`);
    const response = await checker.request.patch(`${baseUrl()}/api/v1/quality/inspections/${id}/review`, {
      headers: await statefulCsrfHeaders(checker), data: { decision: 'passed', expected_result_version: 1 },
    });
    expect(response.status(), await response.text()).toBe(200);
    expect((await response.json()).data.status).toBe('passed');
  }
  const outgoing = fixture.inspections.outgoing;
  const certificate = await checker.request.get(`${baseUrl()}/api/v1/quality/inspections/${outgoing}/coc`, { headers: statefulHeaders() });
  expect(certificate.status(), await certificate.text()).toBe(200);
  expect((await certificate.body()).subarray(0, 4).toString()).toBe('%PDF');
  await checkerContext.close();
  await page.goto(`/quality/inspection-specs/spec/${fixture.spec}`);
  await page.getByRole('button', { name: 'Edit Surface condition' }).click();
  const surfaceCheck = page.getByRole('region', { name: /Check \d+: Surface condition/ });
  await expect(surfaceCheck.getByLabel(/^PASS requirement /)).toBeVisible();
  expect(await page.getByRole('textbox', { name: /^Target / }).evaluateAll(inputs => inputs.some(input => (input as HTMLInputElement).value === '10.0000'))).toBe(true);
  expect(await page.getByRole('textbox', { name: /^Lowest allowed / }).evaluateAll(inputs => inputs.some(input => (input as HTMLInputElement).value === '9.9000'))).toBe(true);
  await page.screenshot({ path: `${evidence}/product-spec-desktop.png`, fullPage: true });
  await page.goto(`/inventory/items/${fixture.item}/quality-plans`);
  await expect(page.getByRole('heading', { name: 'Inspection checklist', exact: true })).toBeVisible();
  await page.setViewportSize({ width: 390, height: 1000 });
  await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  await page.screenshot({ path: `${evidence}/supplier-plan-mobile.png`, fullPage: true });
  expect(errors).toEqual([]);
  const incoming = await page.request.get(`${baseUrl()}/api/v1/quality/inspections/${fixture.inspections.incoming}`, { headers: statefulHeaders() });
  expect((await incoming.json()).data.source_quantity).toBe('125.000');
});

test('authoring stays usable on mobile, dark and shop-floor themes', async ({ page }) => {
  await loginAs(page, fixture.accounts.maker, 'password');
  await page.goto(`/quality/inspection-specs/spec/${fixture.spec}`);
  await page.getByRole('button', { name: 'Edit Surface condition' }).click();
  const surfaceCheck = page.getByRole('region', { name: /Check \d+: Surface condition/ });
  await expect(surfaceCheck.getByLabel(/^PASS requirement /)).toBeVisible();
  for (const mode of ['dark', 'floor']) {
    await page.evaluate(theme => { document.documentElement.dataset.theme = theme; }, mode);
    await page.setViewportSize({ width: 390, height: 1000 });
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    await page.screenshot({ path: `${evidence}/spec-${mode}-390.png`, fullPage: true });
  }
  await page.goto(`/inventory/items/${fixture.item}/quality-plans`);
  await expect(page.getByLabel('Acceptance criterion / reference 1')).toBeVisible();
  for (const mode of ['dark', 'floor']) {
    await page.evaluate(theme => { document.documentElement.dataset.theme = theme; }, mode);
    await page.setViewportSize({ width: 390, height: 1000 });
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    await page.screenshot({ path: `${evidence}/plan-${mode}-390.png`, fullPage: true });
  }
});


test('Warehouse, PPC and employee accounts cannot overwrite or review Quality evidence', async ({ browser }) => {
  test.setTimeout(120_000);
  for (const role of ['employee', 'warehouse_staff', 'ppc_head']) {
    const context = await browser.newContext();
    const page = await context.newPage();
    await loginAs(page, fixture.accounts[role], 'password');
    const headers = await statefulCsrfHeaders(page);
    const url = `${baseUrl()}/api/v1/quality/inspections/${fixture.inspections.incoming}`;
    const record = await page.request.post(`${url}/batch-result`, { headers, data: { expected_result_version: 2, items: [], coverage_confirmed: true } });
    expect(record.status()).toBe(403);
    const review = await page.request.patch(`${url}/review`, { headers, data: { decision: 'passed', expected_result_version: 2 } });
    expect(review.status()).toBe(403);
    await context.close();
  }
});
