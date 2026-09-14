import { firefox } from '../../spa/node_modules/playwright/index.mjs';

const base = 'http://localhost';
const artifact = '/home/kwat0g/Desktop/kwatog/audit/live-2026-09-14/artifacts';
const browser = await firefox.launch({ headless: true });
const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
const page = await context.newPage();
const results = [];
let actor = 'signed-out';
let lastLogin = 0;
const ids = { product: 'dGypLxpvAg', delivery: 'GqkbAVwxd1', complaint: 'GqkbAVwxd1', ncr: 'MYAbJg3wBl', draftInspection: 'MYAbJ3wBl7' };

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const data = (body) => body?.data ?? body;
const list = (body) => Array.isArray(data(body)) ? data(body) : [];
const idOf = (body) => data(body)?.id ?? null;

function record(status, name, detail = '') {
  results.push({ status, actor, name, detail });
  console.log(`${status} ${name}${detail ? ` :: ${detail}` : ''}`);
}
function expect(name, response, statuses, detail = '') {
  const expected = Array.isArray(statuses) ? statuses : [statuses];
  const ok = expected.includes(response.status);
  record(ok ? 'PASS' : 'FAIL', name, `HTTP ${response.status}; expected ${expected.join('/')} ${detail}; body=${JSON.stringify(response.body ?? '').slice(0, 1000)}`);
  return ok;
}
function blocked(name, detail) { record('BLOCKED', name, detail); }

async function api(path, options = {}) {
  return page.evaluate(async ({ path, options }) => {
    const token = document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.slice(10);
    const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...(token ? { 'X-XSRF-TOKEN': decodeURIComponent(token) } : {}), ...(options.multipart ? {} : options.body === undefined ? {} : { 'Content-Type': 'application/json' }) };
    let body;
    if (options.multipart) {
      const form = new FormData();
      for (const [key, value] of Object.entries(options.fields ?? {})) form.append(key, String(value));
      form.append(options.fileField ?? 'file', new Blob([new Uint8Array([37, 80, 68, 70, 45, 49, 46, 52, 10, 37, 66, 53])], { type: options.mime ?? 'application/pdf' }), options.filename ?? 'b5-proof.pdf');
      body = form;
    } else if (options.body !== undefined) body = JSON.stringify(options.body);
    const response = await fetch(`/api/v1${path}`, { method: options.method ?? 'GET', credentials: 'include', headers, body });
    const text = await response.text();
    let parsed; try { parsed = JSON.parse(text); } catch { parsed = text; }
    return { status: response.status, body: parsed, contentType: response.headers.get('content-type'), disposition: response.headers.get('content-disposition') };
  }, { path, options });
}
async function logout() { if (actor !== 'signed-out') await api('/auth/logout', { method: 'POST' }).catch(() => {}); await context.clearCookies(); actor = 'signed-out'; }
async function login(alias, email) {
  await logout();
  const wait = Math.max(0, 13000 - (Date.now() - lastLogin)); if (wait) await sleep(wait);
  await page.goto(`${base}/login`, { waitUntil: 'domcontentloaded' });
  await page.locator('input[type="email"], input[name="email"]').first().fill(email);
  await page.locator('input[type="password"]').first().fill('password');
  await page.getByRole('button', { name: /sign in|log in/i }).click();
  await page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 20000 });
  actor = alias; lastLogin = Date.now(); expect(`${alias} authenticated`, await api('/auth/user'), 200);
}
async function ui(path, name) {
  const response = await page.goto(`${base}${path}`, { waitUntil: 'domcontentloaded' });
  await page.locator('body').waitFor({ state: 'visible', timeout: 15000 });
  await page.waitForFunction(() => document.body.innerText.trim().length > 40, null, { timeout: 20000 });
  await page.screenshot({ path: `${artifact}/s32-s40-${name}.png`, fullPage: true }).catch(() => {});
  record('PASS', `${name} UI`, `document=${response?.status() ?? 'none'} uiUrl=${page.url()}`);
}

try {
  // S33 proof upload/delete/restore on a seeded scheduled delivery. The delivery
  // itself is untouched; only the disposable proof row is changed.
  await login('impex', 'impex@ogami.test');
  await ui(`/supply-chain/deliveries/${ids.delivery}`, 'impex-delivery-detail');
  const proof = await api(`/supply-chain/deliveries/${ids.delivery}/proofs`, { method: 'POST', multipart: true, fields: { proof_type: 'other', notes: 'Disposable B5 proof' }, filename: 'b5-proof.pdf' });
  expect('S33 disposable proof upload', proof, 201);
  ids.proof = idOf(proof.body);
  if (ids.proof) {
    const view = await api(`/supply-chain/deliveries/${ids.delivery}/proofs/${ids.proof}/view`);
    expect('S33 proof view/download', view, 200);
    record(view.contentType?.includes('pdf') ? 'PASS' : 'FAIL', 'S33 proof PDF MIME', `contentType=${view.contentType} disposition=${view.disposition}`);
    await expect('S33 proof archive', await api(`/supply-chain/deliveries/${ids.delivery}/proofs/${ids.proof}`, { method: 'DELETE' }), 204);
    await expect('S33 proof restore', await api(`/supply-chain/deliveries/${ids.delivery}/proofs/${ids.proof}/restore`, { method: 'PATCH' }), 200);
    await expect('S33 proof final archive', await api(`/supply-chain/deliveries/${ids.delivery}/proofs/${ids.proof}`, { method: 'DELETE' }), 204);
  }
  await expect('S33 impex customer-confirm wrong-role 403', await api(`/supply-chain/deliveries/${ids.delivery}/confirm`, { method: 'POST', body: { receiver_name: 'B5 wrong-role probe' } }), 403);
  const deliveryList = await api('/supply-chain/deliveries?per_page=100');
  const disposableDelivery = list(deliveryList.body).find((row) => row.id === ids.delivery);
  if (disposableDelivery?.status === 'scheduled') blocked('S33 delivery status/customer confirmation', 'Only seeded scheduled delivery was available; a disposable SO lacked a passed outgoing inspection and the delivery itself was preserved.');

  // S36 — use a fresh available machine/mold, then execute the disposable WO.
  await login('production', 'production@ogami.test');
  await expect('S36 cleanup prior disposable machine', await api('/mrp/machines/zW1p05N0BA', { method: 'DELETE' }), [204, 422, 404]);
  const machine = await api('/mrp/machines', { method: 'POST', body: { machine_code: `B5MC-${Date.now().toString().slice(-7)}`, name: 'B5 Execution Machine', tonnage: 100, machine_type: 'injection_molder', operators_required: '1.0', available_hours_per_day: '8.0', status: 'idle' } });
  expect('S36 execution machine create', machine, 201); ids.machine = idOf(machine.body);
  const mold = await api('/mrp/molds', { method: 'POST', body: { mold_code: `B5MO-${Date.now().toString().slice(-7)}`, name: 'B5 Execution Mold', product_id: ids.product, cavity_count: 1, cycle_time_seconds: 30, output_rate_per_hour: 120, setup_time_minutes: 10, max_shots_before_maintenance: 100, lifetime_max_shots: 1000, status: 'available', location: 'B5 execution rack' } });
  expect('S36 execution mold create', mold, 201); ids.mold = idOf(mold.body);
  if (ids.mold && ids.machine) {
    await expect('S34 compatibility sync machine assignment', await api(`/mrp/molds/${ids.mold}/compatibility`, { method: 'POST', body: { machine_ids: [ids.machine] } }), 200);
    await expect('S34 mold history read under production', await api(`/mrp/molds/${ids.mold}/history`), 200);
    const routing = await api('/production/routings', { method: 'POST', body: { product_id: ids.product, notes: 'B5 executable routing', operations: [{ sequence: 1, operation_name: 'Injection', work_center: 'B5', machine_id: ids.machine, mold_id: ids.mold, setup_time_minutes: '10.00', cycle_time_minutes: '0.50', labor_rate_per_hour: '100.0000', machine_rate_per_hour: '200.0000', overhead_rate_per_hour: '50.0000', qc_required: true }] } });
    expect('S36 executable routing create', routing, 201);
    ids.routing = idOf(routing.body);
    if (ids.routing) {
      await login('ppc', 'ppc@ogami.test');
      await expect('S36 executable routing duplicate', await api(`/production/routings/${ids.routing}/duplicate`, { method: 'POST' }), 201);
      await login('production', 'production@ogami.test');
    }
    const wo = await api('/production/work-orders', { method: 'POST', body: { product_id: ids.product, machine_id: ids.machine, mold_id: ids.mold, quantity_target: 2, planned_start: '2026-09-14 11:00:00', planned_end: '2026-09-14 19:00:00', priority: 2, work_order_class: 'standard' } });
    expect('S36 executable WO create', wo, 201); ids.workOrder = idOf(wo.body);
    if (ids.workOrder) {
      await expect('S36 executable WO confirm', await api(`/production/work-orders/${ids.workOrder}/confirm`, { method: 'POST', body: { machine_id: ids.machine, mold_id: ids.mold } }), 200);
      await expect('S36 executable WO start', await api(`/production/work-orders/${ids.workOrder}/start`, { method: 'POST' }), 200);
      const categories = await api('/production/downtime-categories');
      const category = data(categories.body)?.[0]?.value ?? 'unplanned_breakdown';
      await expect('S36 executable WO pause', await api(`/production/work-orders/${ids.workOrder}/pause`, { method: 'POST', body: { reason: 'B5 downtime probe', category } }), 200);
      await expect('S36 executable WO resume', await api(`/production/work-orders/${ids.workOrder}/resume`, { method: 'POST' }), 200);
      const defects = await api('/production/defect-types');
      const defect = list(defects.body)[0];
      const output = await api(`/production/work-orders/${ids.workOrder}/outputs`, { method: 'POST', body: { good_count: 1, reject_count: 1, remarks: 'B5 output good and reject', defects: defect ? [{ defect_type_id: defect.id, count: 1 }] : [] } });
      expect('S36 executable WO output good/reject', output, 201);
      await expect('S36 executable WO complete', await api(`/production/work-orders/${ids.workOrder}/complete`, { method: 'POST' }), 200);
      await expect('S36 executable WO close', await api(`/production/work-orders/${ids.workOrder}/close`, { method: 'POST' }), [200, 422]);
    }
  } else blocked('S36 executable WO/output/downtime', 'Machine or mold creation did not return a hash ID.');
  if (ids.mold) {
    await expect('S34 execution mold decommission cleanup', await api(`/mrp/molds/${ids.mold}/decommission`, { method: 'POST' }), [200, 422]);
    await expect('S34 execution mold archive cleanup', await api(`/mrp/molds/${ids.mold}`, { method: 'DELETE' }), 204);
  }
  if (ids.machine) await expect('S36 execution machine archive cleanup', await api(`/mrp/machines/${ids.machine}`, { method: 'DELETE' }), 204);

  // S38 — complete the same complaint's 8D and downstream NCR disposition.
  await login('crm', 'crm@ogami.test');
  await ui(`/crm/complaints/${ids.complaint}`, 'crm-complaint-detail');
  await expect('S38 complete 8D data', await api(`/crm/complaints/${ids.complaint}/8d`, { method: 'PATCH', body: {
    d1_team: 'B5 cross-functional team', d2_problem: 'B5 dimensional complaint', d3_containment: 'Quarantine affected batch', d4_root_cause: 'Process variation', d5_corrective_action: 'Adjust process window', d6_verification: 'Verify three consecutive lots', d7_prevention: 'Update control plan', d8_recognition: 'Customer communication recorded',
  } }), 200);
  const finalized = await api(`/crm/complaints/${ids.complaint}/8d/finalize`, { method: 'POST' });
  expect('S38 8D finalize', finalized, 200);
  if (finalized.status === 200) {
    const pdf = await api(`/crm/complaints/${ids.complaint}/8d/pdf`);
    expect('S38 8D PDF', pdf, 200);
    record(pdf.contentType?.includes('pdf') ? 'PASS' : 'FAIL', 'S38 8D PDF MIME', `contentType=${pdf.contentType} disposition=${pdf.disposition}`);
    await expect('S38 complaint resolve', await api(`/crm/complaints/${ids.complaint}/resolve`, { method: 'POST' }), 200);
    await expect('S38 complaint close', await api(`/crm/complaints/${ids.complaint}/close`, { method: 'POST' }), 200);
    await expect('S38 complaint close replay', await api(`/crm/complaints/${ids.complaint}/close`, { method: 'POST' }), 422);
  }
  await expect('S38 CRM notification feed', await api('/notifications?per_page=50'), 200);

  await login('qc', 'qc@ogami.test');
  const ncr = await api(`/quality/ncrs/${ids.ncr}`);
  expect('S38 complaint-created NCR detail', ncr, 200);
  await expect('S38 NCR rework disposition', await api(`/quality/ncrs/${ids.ncr}/disposition`, { method: 'PATCH', body: { disposition: 'rework', root_cause: 'Process variation', corrective_action: 'Rework affected quantity and verify' } }), 200);
  await expect('S38 NCR close after disposition', await api(`/quality/ncrs/${ids.ncr}/close`, { method: 'POST' }), 200);
  await expect('S38 NCR terminal replay', await api(`/quality/ncrs/${ids.ncr}/close`, { method: 'POST' }), 422);

  // S40 — the request contract derives pass/fail from measured_value. Send the
  // actual nominal values only, then prove terminal completion/replay.
  const draft = await api(`/quality/inspections/${ids.draftInspection}`);
  expect('S40 draft inspection re-read', draft, 200);
  const measurements = data(draft.body)?.measurements ?? [];
  console.log(`S40 measurement-row-shape ${JSON.stringify(measurements).slice(0, 3000)}`);
  if (measurements.length) {
    const rows = measurements.map((measurement) => {
      const nominal = measurement.nominal_value ?? measurement.spec_item?.nominal_value ?? measurement.inspection_spec_item?.nominal_value ?? measurement.parameter?.nominal_value ?? '100.0000';
      return { id: measurement.id, measured_value: nominal, notes: 'B5 nominal actual measurement' };
    });
    await expect('S40 actual measurements derived evaluation', await api(`/quality/inspections/${ids.draftInspection}/measurements`, { method: 'PATCH', body: { measurements: rows } }), 200);
    await expect('S40 draft inspection complete', await api(`/quality/inspections/${ids.draftInspection}/complete`, { method: 'POST' }), 200);
    await expect('S40 draft inspection complete replay', await api(`/quality/inspections/${ids.draftInspection}/complete`, { method: 'POST' }), 422);
  } else blocked('S40 actual measurement evaluation', 'Draft inspection has no measurement rows.');
  await expect('S40 invalid measurement precision', await api(`/quality/inspections/${ids.draftInspection}/measurements`, { method: 'PATCH', body: { measurements: [{ id: measurements[0]?.id, measured_value: '10.00005' }] } }), 422);
} catch (error) {
  record('FAIL', 'S32-S40 continuation harness', error instanceof Error ? error.stack ?? error.message : String(error));
} finally {
  await logout().catch(() => {});
  await browser.close();
}
console.log(JSON.stringify({ ids, counts: results.reduce((counts, row) => ({ ...counts, [row.status]: (counts[row.status] ?? 0) + 1 }), {}), results }, null, 2));
