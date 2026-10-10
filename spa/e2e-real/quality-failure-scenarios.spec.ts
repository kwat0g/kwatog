import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { baseUrl, loginAs, statefulHeaders, statefulCsrfHeaders } from './helpers';

if (baseUrl() !== 'http://localhost:18080') throw new Error('Failure scenarios require the isolated audit application.');
const fixturePath = process.env.QC_CHECKLIST_FIXTURE;
if (!fixturePath) throw new Error('QC_CHECKLIST_FIXTURE must identify fresh isolated records.');
const fixture = JSON.parse(readFileSync(fixturePath, 'utf8')) as {
  accounts: Record<string, string>; inspections: Record<string, string>;
};

for (const stage of ['outgoing', 'incoming']) {
  test(`${stage}: failure persists, cannot be overridden to PASS, and requires independent review`, async ({ page, browser }) => {
    const id = fixture.inspections[stage];
    await loginAs(page, fixture.accounts.maker, 'password');
    await page.goto(`/quality/inspections/${id}`);
    let panel = page.getByRole('region', { name: 'Inspection checklist', exact: true });
    await panel.getByRole('button', { name: 'FAIL Surface condition', exact: true }).click();
    const problem = stage === 'outgoing' ? 'One scratched piece also has incorrect identification.' : 'Required supplier certificate missing.';
    await panel.getByRole('textbox', { name: 'Problem for Surface condition', exact: true }).fill(problem);
    const draftResponse = page.waitForResponse(response => response.url().includes(`/inspections/${id}/item-results`) && response.request().method() === 'POST');
    await panel.getByRole('button', { name: 'Save draft', exact: true }).click();
    expect((await draftResponse).status()).toBe(200);
    await page.reload();
    panel = page.getByRole('region', { name: 'Inspection checklist', exact: true });
    await expect(panel.getByRole('textbox', { name: 'Problem for Surface condition', exact: true })).toHaveValue(problem);
    await panel.getByRole('button', { name: 'PASS Fit check', exact: true }).click();
    await panel.getByRole('button', { name: `${stage === 'outgoing' ? 'FAIL' : 'PASS'} Identification`, exact: true }).click();
    if (stage === 'outgoing') await panel.getByRole('textbox', { name: 'Problem for Identification', exact: true }).fill(problem);
    await panel.getByRole('button', { name: stage === 'outgoing' ? 'Yes' : 'No', exact: true }).click();
    if (stage === 'outgoing') await panel.getByRole('spinbutton', { name: 'Unique defective pieces' }).fill('1');
    else await expect(panel.getByRole('spinbutton')).toHaveCount(0);
    await panel.getByRole('checkbox', { name: /I confirm (every piece|all required checks)/ }).check();
    const submission = page.waitForResponse(response => response.url().endsWith(`/inspections/${id}/batch-result`) && response.request().method() === 'POST');
    await panel.getByRole('button', { name: 'Submit inspection', exact: true }).click();
    const submitted = await submission;
    expect(submitted.status(), await submitted.text()).toBe(200);
    const saved = (await submitted.json()).data;
    expect(saved.status).toBe('awaiting_review');
    expect(saved.recorded_result).toBe('failed');
    expect(saved.sample_defect_count).toBe(stage === 'outgoing' ? 1 : 0);
    expect(saved.measurements.every((row: { measured_value: unknown }) => row.measured_value === null)).toBe(true);
    const context = await browser.newContext();
    try {
      const checker = await context.newPage();
      await loginAs(checker, fixture.accounts.checker, 'password');
      const headers = await statefulCsrfHeaders(checker);
      const url = `${baseUrl()}/api/v1/quality/inspections/${id}`;
      const override = await checker.request.patch(`${url}/review`, { headers, data: { decision: 'passed', expected_result_version: saved.result_version } });
      expect(override.status()).toBe(422);
      const unchanged = await checker.request.get(url, { headers: statefulHeaders() });
      expect((await unchanged.json()).data.status).toBe('awaiting_review');
      const reviewed = await checker.request.patch(`${url}/review`, { headers, data: { decision: 'failed', remarks: 'Problem independently verified.', expected_result_version: saved.result_version } });
      expect(reviewed.status(), await reviewed.text()).toBe(200);
      const final = (await reviewed.json()).data;
      expect(final.status).toBe('failed');
      expect(Number(final.accepted_quantity)).toBe(0);
      const reports = await checker.request.get(`${baseUrl()}/api/v1/quality/ncrs?inspection_id=${encodeURIComponent(id)}`, { headers: statefulHeaders() });
      expect(reports.status(), await reports.text()).toBe(200);
      const incidents = (await reports.json()).data;
      expect(incidents.length).toBe(1);
      const certificate = await checker.request.get(`${url}/coc`, { headers: statefulHeaders() });
      expect(certificate.ok()).toBe(false);
      await page.reload();
      await expect(page.getByRole('region', { name: 'Inspection checklist', exact: true }).getByRole('button', { name: 'Submit inspection', exact: true })).toHaveCount(0);
    } finally {
      await context.close();
    }
  });
}
