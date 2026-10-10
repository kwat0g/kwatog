const { chromium, expect } = require('/home/ec2-user/kwatog/spa/node_modules/@playwright/test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const baseURL = 'http://localhost:18080';
const dir = __dirname;
const fixture = JSON.parse(fs.readFileSync(path.join(dir, 'fixture.json'), 'utf8'));
const stages = ['in_process', 'outgoing', 'customer_return', 'supplier_return', 'incoming'];
const headers = { Origin: baseURL, Referer: `${baseURL}/`, Accept: 'application/json' };
const results = [];
const errors = [];

async function actor(browser, key) {
  const context = await browser.newContext({ baseURL, viewport: { width: 1440, height: 1000 } });
  const page = await context.newPage();
  await context.request.get('/sanctum/csrf-cookie', { headers });
  const token = (await context.cookies()).find(cookie => cookie.name === 'XSRF-TOKEN');
  const response = await context.request.post('/api/v1/auth/sign-in', {
    headers: { ...headers, 'X-XSRF-TOKEN': decodeURIComponent(token.value) },
    data: { email: fixture.accounts[key], password: 'password' },
  });
  assert.equal(response.status(), 200, await response.text());
  page.on('pageerror', error => errors.push(error.message));
  page.on('response', response => { if (response.status() >= 500) errors.push(`${response.status()} ${response.url()}`); });
  return { context, page };
}

async function write(actor, method, url, data) {
  const token = (await actor.context.cookies()).find(cookie => cookie.name === 'XSRF-TOKEN');
  return actor.context.request[method](url, {
    headers: { ...headers, 'X-XSRF-TOKEN': decodeURIComponent(token.value) }, data,
  });
}

async function save(page, panel, id, draft) {
  const endpoint = draft ? 'item-results' : 'batch-result';
  const responsePromise = page.waitForResponse(response => response.url().endsWith(`/inspections/${id}/${endpoint}`) && response.request().method() === 'POST');
  await panel.getByRole('button', { name: draft ? 'Save draft' : 'Submit inspection', exact: true }).click();
  const response = await responsePromise;
  assert.equal(response.status(), 200, await response.text());
  return { record: (await response.json()).data, payload: response.request().postDataJSON() };
}

(async () => {
  const browser = await chromium.launch({ headless: true });
  try {
    const maker = await actor(browser, 'maker');
    const checker = await actor(browser, 'checker');
    for (const scenario of ['pass', 'size_fail', 'document_fail']) {
      for (const stage of stages) {
        await new Promise(resolve => setTimeout(resolve, 10000));
        const id = fixture.inspections[scenario][stage];
        const url = `/api/v1/quality/inspections/${id}`;
        const page = maker.page;
        await page.goto(`/quality/inspections/${id}`);
        let panel = page.getByRole('region', { name: 'Inspection checklist', exact: true });
        const size = () => panel.getByRole('textbox', { name: 'Actual size for Fit check', exact: true });
        await expect(size()).toHaveValue('');
        await expect(panel.getByRole('button', { name: 'PASS Fit check', exact: true })).toBeDisabled();
        await expect(panel.getByText('Fits the approved inspection gauge.', { exact: true })).toBeVisible();
        await expect(panel.getByText('Lowest allowed: 9.9000 mm', { exact: true })).toBeVisible();
        await size().fill('9.9');
        await expect(panel.getByRole('button', { name: 'PASS Fit check', exact: true })).toHaveAttribute('aria-pressed', 'true');
        await size().fill('10.1');
        await expect(panel.getByRole('button', { name: 'PASS Fit check', exact: true })).toHaveAttribute('aria-pressed', 'true');
        await size().fill('10.10001');
        await expect(panel.getByRole('button', { name: 'Submit inspection', exact: true })).toBeDisabled();
        await size().fill('10.2');
        await expect(panel.getByRole('button', { name: 'PASS Fit check', exact: true })).toBeDisabled();
        await expect(panel.getByRole('button', { name: 'FAIL Fit check', exact: true })).toHaveAttribute('aria-pressed', 'true');
        await size().fill(scenario === 'size_fail' ? '10.2' : '10');
        for (const name of ['Surface condition', 'Identification']) {
          const failed = scenario === 'size_fail' || (scenario === 'document_fail' && name === 'Identification');
          await panel.getByRole('button', { name: `${failed ? 'FAIL' : 'PASS'} ${name}`, exact: true }).click();
          if (failed) await panel.getByRole('textbox', { name: `Problem for ${name}`, exact: true }).fill(scenario === 'size_fail' ? 'One defective piece fails several checks.' : 'Required supplier certificate missing.');
        }
        if (scenario === 'size_fail') await panel.getByRole('textbox', { name: 'Problem for Fit check', exact: true }).fill('One piece measures 10.2 mm, above the approved limit.');
        const draft = await save(page, panel, id, true);
        assert.equal(draft.record.result_version, 1);
        assert.equal(draft.record.measurements.find(row => row.parameter_name === 'Fit check').measured_value, scenario === 'size_fail' ? '10.2000' : '10.0000');
        await page.reload();
        panel = page.getByRole('region', { name: 'Inspection checklist', exact: true });
        await expect(size()).toHaveValue(scenario === 'size_fail' ? '10.2000' : '10.0000');
        const stale = await write(maker, 'post', `${url}/item-results`, { expected_result_version: 0, items: draft.payload.items, submit: false });
        assert.equal(stale.status(), 422, await stale.text());
        if (scenario !== 'pass') {
          await panel.getByRole('button', { name: scenario === 'size_fail' ? 'Yes' : 'No', exact: true }).click();
          const pieces = panel.getByRole('spinbutton', { name: 'Unique defective pieces', exact: true });
          if (scenario === 'size_fail' && stage !== 'incoming') await pieces.fill('1');
          else await expect(pieces).toHaveCount(0);
        }
        await panel.getByRole('checkbox', { name: /I confirm (every piece|all required checks)/ }).check();
        if (stage === 'outgoing') {
          for (const width of [1440, 390]) {
            await page.setViewportSize({ width, height: 1000 });
            await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(true);
            await page.screenshot({ path: path.join(dir, `${scenario}-${width}.png`), fullPage: true });
          }
          await page.setViewportSize({ width: 1440, height: 1000 });
        }
        const submitted = await save(page, panel, id, false);
        const saved = submitted.record;
        const decision = scenario === 'pass' ? 'passed' : 'failed';
        assert.equal(saved.recorded_result, decision);
        assert.equal(saved.result_version, 2);
        assert.equal(saved.sample_defect_count, scenario === 'size_fail' ? 1 : 0);
        assert(saved.measurements.filter(row => row.parameter_type !== 'dimensional').every(row => row.measured_value === null));
        const replay = await write(maker, 'post', `${url}/batch-result`, submitted.payload);
        assert.equal(replay.status(), 422, await replay.text());
        if (stage === 'in_process') assert.equal(saved.status, decision);
        else {
          assert.equal(saved.status, 'awaiting_review');
          assert.equal(Number(saved.accepted_quantity), 0);
          const self = await write(maker, 'patch', `${url}/review`, { expected_result_version: 2, decision, remarks: 'Review requested with evidence.' });
          assert.equal(self.status(), 403, await self.text());
          if (scenario !== 'pass') {
            const override = await write(checker, 'patch', `${url}/review`, { expected_result_version: 2, decision: 'passed', remarks: 'Attempted acceptance despite saved failure.' });
            assert.equal(override.status(), 422, await override.text());
          }
          const review = await write(checker, 'patch', `${url}/review`, { expected_result_version: 2, decision, remarks: 'Independent inspection evidence verified.' });
          assert.equal(review.status(), 200, await review.text());
          assert.equal((await review.json()).data.status, decision);
        }
        if (scenario !== 'pass') {
          const reports = await checker.context.request.get(`/api/v1/quality/ncrs?inspection_id=${encodeURIComponent(id)}`, { headers });
          assert.equal(reports.status(), 200, await reports.text());
          assert.equal((await reports.json()).data.length, 1);
          const final = await checker.context.request.get(url, { headers });
          assert.equal(Number((await final.json()).data.accepted_quantity), 0);
        }
        if (stage === 'outgoing') {
          const certificate = await checker.context.request.get(`${url}/coc`, { headers });
          assert.equal(certificate.status(), scenario === 'pass' ? 200 : 422, await certificate.text());
          if (scenario === 'pass') assert.equal((await certificate.body()).subarray(0, 4).toString(), '%PDF');
        }
        await page.reload();
        await expect(page.getByRole('region', { name: 'Inspection checklist', exact: true }).getByRole('button', { name: 'Submit inspection', exact: true })).toHaveCount(0);
        results.push({ stage, scenario, passed: true });
        console.log(`${stage} ${scenario}: PASS`);
      }
    }
    for (const role of ['employee', 'warehouse_staff', 'ppc_head']) {
      const user = await actor(browser, role);
      for (const stage of stages) {
        const url = `/api/v1/quality/inspections/${fixture.inspections.pass[stage]}`;
        const record = await write(user, 'post', `${url}/batch-result`, { expected_result_version: 3, items: [], coverage_confirmed: true });
        assert.equal(record.status(), 403, await record.text());
        const review = await write(user, 'patch', `${url}/review`, { expected_result_version: 3, decision: 'passed' });
        assert.equal(review.status(), 403, await review.text());
      }
      console.log(`${role}: all five stage writes and reviews denied`);
      await user.context.close();
    }
    assert.deepEqual(errors, []);
    console.log('Real headless browser checks passed. No mocked API routes. No production business writes.');
  } finally {
    fs.writeFileSync(path.join(dir, 'browser-result.json'), JSON.stringify({ baseURL, results, errors, productionBusinessWrites: 0 }, null, 2));
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
