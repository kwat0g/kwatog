import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { baseUrl, loginAs, statefulHeaders, statefulCsrfHeaders } from './helpers';

if (baseUrl() !== 'http://localhost:18080')
  throw new Error('Quarantine recovery requires the isolated audit application.');
const fixturePath = process.env.QC_RECOVERY_FIXTURE;
if (!fixturePath) throw new Error('QC_RECOVERY_FIXTURE must identify fresh isolated records.');
const fixture = JSON.parse(readFileSync(fixturePath, 'utf8')) as {
  accounts: Record<string, string>;
  ncr: string;
  source: string;
  inspection: string;
};

test('late receipt quarantine retries once; concession requires independent approval evidence', async ({
  page,
  browser,
}) => {
  await loginAs(page, fixture.accounts.maker, 'password');
  await page.goto(`/quality/ncrs/${fixture.ncr}`);
  const recovery = page
    .locator('div.bg-canvas.border')
    .filter({ has: page.getByRole('heading', { name: 'Accepted material review', exact: true }) });
  const response = page.waitForResponse(
    (r) =>
      r.url().includes(`/ncrs/${fixture.ncr}/retry-quarantine`) && r.request().method() === 'POST',
  );
  await recovery
    .getByRole('button', { name: 'Retry missing quarantine holds', exact: true })
    .click();
  expect((await response).status()).toBe(200);
  await expect(
    recovery.getByRole('button', { name: 'Retry missing quarantine holds', exact: true }),
  ).toHaveCount(0);
  const incidentResponse = await page.request.get(
    `${baseUrl()}/api/v1/quality/ncrs/${fixture.ncr}`,
    { headers: statefulHeaders() },
  );
  const incident = (await incidentResponse.json()).data;
  expect(incident.material_review_records).toHaveLength(1);
  const hold = incident.material_review_records[0].id;
  await page.goto(`/inventory/mrb/${hold}`);
  await expect(page.getByRole('button', { name: 'Release', exact: true })).toHaveCount(0);
  const context = await browser.newContext();
  try {
    const checker = await context.newPage();
    await loginAs(checker, fixture.accounts.checker, 'password');
    const headers = await statefulCsrfHeaders(checker);
    const releaseUrl = `${baseUrl()}/api/v1/inventory/mrb/${hold}/release`;
    const data = {
      disposition: 'use_as_is',
      target_location_id: fixture.source,
      inspected_quantity: '100',
      evidence_reference: 'REINSPECTION-100',
      recovery_passed: true,
    };
    const missing = await checker.request.post(releaseUrl, { headers, data });
    expect(missing.status(), await missing.text()).toBe(422);
    const same = await checker.request.post(releaseUrl, {
      headers,
      data: { ...data, concession_approval_reference: data.evidence_reference },
    });
    expect(same.status(), await same.text()).toBe(422);
    await checker.goto(`/inventory/mrb/${hold}`);
    await checker.getByRole('button', { name: 'Release', exact: true }).click();
    const modal = checker.getByRole('dialog');
    await modal.getByRole('combobox', { name: /Disposition/ }).selectOption('use_as_is');
    await modal
      .getByRole('textbox', { name: 'Completed reinspection evidence reference', exact: true })
      .fill(data.evidence_reference);
    await modal
      .getByRole('checkbox', {
        name: 'Completed reinspection confirms all held material meets the approved concession.',
        exact: true,
      })
      .check();
    await expect(modal.getByRole('button', { name: 'Release', exact: true })).toBeDisabled();
    await modal
      .getByRole('textbox', {
        name: 'Concession approval / customer sign-off reference',
        exact: true,
      })
      .fill('CUSTOMER-SIGNOFF-COSMETIC-ONLY');
    const releasedResponse = checker.waitForResponse(
      (r) => r.url().includes(`/mrb/${hold}/release`) && r.request().method() === 'POST',
    );
    await modal.getByRole('button', { name: 'Release', exact: true }).click();
    expect((await releasedResponse).status()).toBe(200);
    await expect(
      checker.locator('div.bg-canvas.border').filter({
        has: checker.getByRole('heading', { name: 'Concession approval', exact: true }),
      }),
    ).toContainText('CUSTOMER-SIGNOFF-COSMETIC-ONLY');
    const retry = await checker.request.post(
      `${baseUrl()}/api/v1/quality/ncrs/${fixture.ncr}/retry-quarantine`,
      { headers },
    );
    expect(retry.status(), await retry.text()).toBe(200);
    expect((await retry.json()).data.material_review_records).toHaveLength(1);
  } finally {
    await context.close();
  }
});
