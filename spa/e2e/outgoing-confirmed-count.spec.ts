import { readFileSync } from 'node:fs';
import { expect, test, type Browser, type BrowserContext, type Page } from '@playwright/test';
import { loginAs, statefulCsrfHeaders, statefulHeaders } from '../e2e-real/helpers';

type Fixture = {
  tag: string;
  wo: string;
  quantity: number;
  item: string;
  accounts: Record<string, { email: string }>;
};

type Scenario = { label: string; manifestEnv: string; defects: number; result: 'passed' | 'failed' };

const scenarios: Scenario[] = [
  { label: 'zero defects', manifestEnv: 'E2E_FINISH_FIRST_ZERO_MANIFEST', defects: 0, result: 'passed' },
  { label: 'five defects', manifestEnv: 'E2E_FINISH_FIRST_FIVE_MANIFEST', defects: 5, result: 'failed' },
];

function fixtureFor(scenario: Scenario): Fixture {
  const path = process.env[scenario.manifestEnv];
  if (!path) throw new Error(`Set ${scenario.manifestEnv} to its disposable finish-first fixture manifest.`);
  const fixture = JSON.parse(readFileSync(path, 'utf8')) as Fixture;
  if (!fixture.tag.startsWith('ff-')) throw new Error(`Refusing untagged finish-first fixture: ${path}`);
  return fixture;
}

async function loginRoles(browser: Browser, fixture: Fixture) {
  const contexts: BrowserContext[] = [];
  const pages: Record<'manager' | 'recorder' | 'qc' | 'checker' | 'warehouse', Page> = {} as never;
  for (const role of ['manager', 'recorder', 'qc', 'checker', 'warehouse'] as const) {
    const context = await browser.newContext();
    contexts.push(context);
    pages[role] = await context.newPage();
    pages[role].setDefaultTimeout(20_000);
    await loginAs(pages[role], fixture.accounts[role].email, 'Finish-First-Test-2026!');
  }
  return { contexts, pages };
}

async function api(page: Page, method: string, path: string, data?: unknown) {
  const response = await page.request.fetch(`/api/v1${path}`, {
    method,
    data,
    headers: await statefulCsrfHeaders(page),
  });
  return { status: response.status(), body: await response.json() };
}

async function success(page: Page, method: string, path: string, data?: unknown) {
  const response = await api(page, method, path, data);
  expect(response.status, JSON.stringify(response.body)).toBeGreaterThanOrEqual(200);
  expect(response.status, JSON.stringify(response.body)).toBeLessThan(300);
  return response.body.data;
}

async function stock(page: Page, itemId: string) {
  const response = await page.request.get(`/api/v1/inventory/stock-levels?item_id=${itemId}`, {
    headers: statefulHeaders(),
  });
  expect(response.status(), await response.text()).toBe(200);
  const rows = (await response.json()).data as Array<{ usable_quantity: string; quality_held_quantity: string }>;
  return rows.reduce((totals, row) => ({
    usable: totals.usable + Number(row.usable_quantity),
    held: totals.held + Number(row.quality_held_quantity),
  }), { usable: 0, held: 0 });
}

async function completeWorkOrder(pages: Record<'manager' | 'recorder', Page>, fixture: Fixture) {
  const { manager, recorder } = pages;
  await manager.goto(`/production/work-orders/${fixture.wo}`);
  await manager.getByRole('button', { name: 'Start', exact: true }).click();
  const startDialog = manager.getByRole('dialog');
  const startButton = startDialog.getByRole('button', { name: 'Start production', exact: true });
  await expect(startButton).toBeVisible();
  for (const check of await startDialog.getByRole('checkbox').all()) await check.check();
  await expect(startButton).toBeEnabled();
  const starting = manager.waitForResponse((response) =>
    response.url().endsWith(`${fixture.wo}/start`) && response.request().method() === 'POST',
  );
  await startButton.click();
  expect((await starting).status()).toBe(200);

  await recorder.goto(`/self-service/work-orders/${fixture.wo}`);
  await recorder.getByRole('spinbutton', { name: 'Quantity made' }).fill(String(fixture.quantity));
  const recorded = recorder.waitForResponse((response) =>
    response.url().includes(`/production/my-work-orders/${fixture.wo}/outputs`)
      && response.request().method() === 'POST',
  );
  await recorder.getByRole('button', { name: 'Record production', exact: true }).click();
  expect((await recorded).status()).toBe(200);

  await manager.goto(`/production/work-orders/${fixture.wo}`);
  await manager.getByRole('button', { name: 'Finish production', exact: true }).click();
  const finishDialog = manager.getByRole('dialog');
  const finishing = manager.waitForResponse((response) =>
    response.url().endsWith(`${fixture.wo}/complete`) && response.request().method() === 'POST',
  );
  await finishDialog.getByRole('button', { name: 'Finish production', exact: true }).click();
  expect((await finishing).status()).toBe(200);
}

async function exerciseScenario(browser: Browser, scenario: Scenario) {
  const fixture = fixtureFor(scenario);
  const { contexts, pages } = await loginRoles(browser, fixture);
  try {
    const { manager, recorder, qc, checker, warehouse } = pages;
    await completeWorkOrder({ manager, recorder }, fixture);

    const outputPath = `/production/work-orders/${fixture.wo}/outputs`;
    const originalOutputs = await success(manager, 'GET', outputPath) as Array<{
      id: string; good_count: number; reject_count: number; batch_code: string | null;
    }>;
    expect(originalOutputs).toHaveLength(1);
    expect(originalOutputs[0]).toMatchObject({ good_count: fixture.quantity, reject_count: 0 });
    const sourceSnapshot = originalOutputs.map(({ id, good_count, reject_count, batch_code }) => ({ id, good_count, reject_count, batch_code }));
    expect(await stock(warehouse, fixture.item)).toEqual({ usable: 0, held: fixture.quantity });

    const qcPath = `/quality/work-orders/${fixture.wo}/final-inspection`;
    const before = await success(qc, 'GET', qcPath);
    expect(before.batches).toHaveLength(1);
    expect(before.batches[0].counted_capture).toMatchObject({ version: 3, evidence_method: 'confirmed_checks' });
    const inspectionId = before.batches[0].id as string;
    const unauthorizedSubmit = await api(recorder, 'POST', `${qcPath}/submit`, {});
    expect(unauthorizedSubmit.status).toBe(403);

    await qc.goto(qcPath);
    await expect(qc.getByRole('heading', { name: 'Inspection checklist', exact: true })).toBeVisible();
    await expect(qc.getByText(/Target 10\.0000 mm; range 9\.9000–10\.1000 mm/)).toBeVisible();
    await expect(qc.getByText('No visible damage', { exact: true })).toBeVisible();
    await expect(qc.locator('input[type="number"][step="0.0001"]')).toHaveCount(0);
    await expect(qc.getByRole('textbox', { name: 'Length · piece 1' })).toHaveCount(0);

    if (scenario.defects === 0) {
      await qc.getByRole('button', { name: 'PASS ALL', exact: true }).click();
    } else {
      await qc.getByRole('spinbutton', { name: 'Defective pieces' }).fill(String(scenario.defects));
      await qc.getByText('Apply same problem to several pieces', { exact: true }).click();
      await qc.getByRole('textbox', { name: 'Shared problem' }).fill('Surface damage found.');
      await qc.getByRole('combobox', { name: 'Shared failed check' }).selectOption({ label: 'Appearance' });
      await qc.getByRole('checkbox', { name: 'Select all problems on this page' }).check();
      await qc.getByRole('button', { name: 'Apply to selected pieces', exact: true }).click();
      await expect(qc.getByRole('textbox', { name: /^Problem for piece/ })).toHaveCount(scenario.defects);
      const saved = qc.waitForRequest((request) =>
        request.url().endsWith(`${qcPath}/evidence`) && request.method() === 'PATCH',
      );
      const saveResponse = qc.waitForResponse((response) =>
        response.url().endsWith(`${qcPath}/evidence`) && response.request().method() === 'PATCH',
      );
      await qc.getByRole('button', { name: 'Save draft', exact: true }).click();
      const [evidenceRequest, evidenceResult] = await Promise.all([saved, saveResponse]);
      expect(evidenceResult.status(), await evidenceResult.text()).toBe(200);
      const evidenceBody = evidenceRequest.postDataJSON() as { pieces: Array<{ problem_description: string; requirement_id: string | null }>; readings: unknown[] };
      expect(evidenceBody.pieces).toHaveLength(scenario.defects);
      expect(evidenceBody.pieces.every((piece) => piece.problem_description === 'Surface damage found.' && piece.requirement_id)).toBe(true);
      expect(evidenceBody.readings).toEqual([]);
    }

    const coverage = qc.getByRole('checkbox', { name: /I checked every piece against all listed requirements/ });
    await expect(coverage).not.toBeChecked();
    const submitButton = qc.getByRole('button', { name: 'Submit inspection', exact: true });
    await expect(submitButton).toBeDisabled();
    await coverage.check();
    await expect(submitButton).toBeEnabled();
    const submission = qc.waitForRequest((request) =>
      request.url().endsWith(`${qcPath}/submit`) && request.method() === 'POST',
    );
    const submitResponse = qc.waitForResponse((response) =>
      response.url().endsWith(`${qcPath}/submit`) && response.request().method() === 'POST',
    );
    await submitButton.click();
    const [submitRequest, submitResult] = await Promise.all([submission, submitResponse]);
    expect(submitResult.status(), await submitResult.text()).toBe(200);
    const submitBody = submitRequest.postDataJSON() as { batches: Array<{ inspection_id: string; checks_confirmed?: boolean }> };
    expect(submitBody.batches[0].checks_confirmed).toBe(true);
    expect(submitBody.batches[0].inspection_id).toBe(inspectionId);
    await expect(qc.getByRole('button', { name: 'Approve inspection results', exact: true })).toHaveCount(0);

    const groupAfterSubmit = await success(qc, 'GET', qcPath);
    const ownReview = await api(qc, 'POST', `${qcPath}/review`, { manifest: groupAfterSubmit.manifest, remarks: 'Self-review must be denied.' });
    expect(ownReview.status).toBe(403);
    expect(await stock(warehouse, fixture.item)).toEqual({ usable: 0, held: fixture.quantity });
    expect((await success(manager, 'GET', outputPath)).map((row: { id: string; good_count: number; reject_count: number; batch_code: string | null }) => ({
      id: row.id, good_count: row.good_count, reject_count: row.reject_count, batch_code: row.batch_code,
    }))).toEqual(sourceSnapshot);

    await checker.goto(qcPath);
    if (scenario.defects > 0) {
      await checker.getByRole('textbox', { name: 'Review remarks' }).fill('Five damaged pieces confirmed; segregate for disposition.');
    }
    const reviewing = checker.waitForResponse((response) =>
      response.url().endsWith(`${qcPath}/review`) && response.request().method() === 'POST',
    );
    await checker.getByRole('button', { name: 'Approve inspection results', exact: true }).click();
    expect((await reviewing).status()).toBe(200);

    const reviewed = await success(checker, 'GET', qcPath);
    expect(reviewed.batches[0].status).toBe(scenario.result);
    expect(reviewed.accepted_quantity).toBe(scenario.defects === 0 ? fixture.quantity : 0);
    expect((await success(manager, 'GET', outputPath)).map((row: { id: string; good_count: number; reject_count: number; batch_code: string | null }) => ({
      id: row.id, good_count: row.good_count, reject_count: row.reject_count, batch_code: row.batch_code,
    }))).toEqual(sourceSnapshot);
    expect(await stock(warehouse, fixture.item)).toEqual(scenario.defects === 0
      ? { usable: fixture.quantity, held: 0 }
      : { usable: 0, held: fixture.quantity });

  } finally {
    await Promise.all(contexts.map((context) => context.close().catch(() => {})));
  }
}

for (const scenario of scenarios) {
  test(`outgoing confirmed checks: ${scenario.label}, full coverage, independent review and held stock`, async ({ browser }) => {
    test.setTimeout(180_000);
    await exerciseScenario(browser, scenario);
  });
}
