import { firefox } from '../../spa/node_modules/playwright/index.mjs';
import fs from 'node:fs/promises';

const base = 'http://localhost';
const artifactDir = '/home/kwat0g/Desktop/kwatog/audit/live-2026-09-14/artifacts';
const password = 'password';
const stamp = Date.now().toString().slice(-8);
const ids = {
  customer: 'dGypLxpvAg', product: 'dGypLxpvAg', so: 'OX5b9mbBrk',
  plan: 'XKEbGkbgWk', wo: '0ldwDmpVa9', schedule: 'GkXwO7wP52',
  scrapNcr: 'kABpV2vpKR', scrapComplaint: 'DzAwdKb5ld',
  reworkNcr: '0ldwD5mpVa', reworkComplaint: 'MYAbJ3wBl7',
};
const results = [];
const evidence = [];
const residuals = [];
let actor = 'signed-out';
let guard = 'none';
let lastLogin = 0;
let req = 1000;
const browser = await firefox.launch({ headless: true });
const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
const page = await context.newPage();

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const data = (body) => body?.data ?? body;
const rows = (body) => Array.isArray(data(body)) ? data(body) : (Array.isArray(data(body)?.data) ? data(body).data : []);
const idOf = (body) => data(body)?.id ?? data(body)?.hash_id ?? null;
const short = (value) => JSON.stringify(value ?? '').slice(0, 2200);
function record(status, name, detail = '') { results.push({ status, actor, name, detail }); console.log(`${status} ${actor} ${name}${detail ? ` :: ${detail}` : ''}`); }
function expect(name, response, accepted, detail = '') { const list = Array.isArray(accepted) ? accepted : [accepted]; const ok = list.includes(response.status); record(ok ? 'PASS' : 'FAIL', name, `HTTP ${response.status}; expected ${list.join('/')} ${detail}; body=${short(response.body)}`); return ok; }
function blocked(name, detail) { record('BLOCKED', name, detail); }
function observed(name, detail) { record('OBSERVED', name, detail); }
function keep(type, id, detail) { if (id) residuals.push({ type, id, detail }); }

async function api(path, options = {}) {
  const response = await page.evaluate(async ({ path, options, requestId }) => {
    const sanitize = (value) => {
      if (Array.isArray(value)) return value.map(sanitize);
      if (!value || typeof value !== 'object') return value;
      return Object.fromEntries(Object.entries(value).map(([key, item]) => [key, /password|secret/i.test(key) ? '[REDACTED]' : sanitize(item)]));
    };
    const xsrf = document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.slice('XSRF-TOKEN='.length);
    const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-Request-ID': requestId, ...(xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } : {}), ...(options.multipart ? {} : options.body === undefined ? {} : { 'Content-Type': 'application/json' }), ...(options.headers ?? {}) };
    let body;
    if (options.multipart) {
      const form = new FormData();
      for (const [key, value] of Object.entries(options.fields ?? {})) form.append(key, String(value));
      form.append(options.fileField ?? 'file', new Blob([new Uint8Array([37, 80, 68, 70, 45, 49, 46, 52, 10, 98, 49, 50])], { type: options.mime ?? 'application/pdf' }), options.filename ?? 'live-b12-proof.pdf');
      body = form;
    } else if (options.body !== undefined) body = JSON.stringify(options.body);
    const response = await fetch(path.startsWith('/api/') ? path : `/api/v1${path}`, { method: options.method ?? 'GET', credentials: 'include', headers, body });
    const text = await response.text(); let parsed; try { parsed = JSON.parse(text); } catch { parsed = text; }
    return { status: response.status, body: sanitize(parsed), contentType: response.headers.get('content-type'), disposition: response.headers.get('content-disposition'), requestId: response.headers.get('x-request-id') ?? requestId };
  }, { path, options, requestId: `LIVE-B12-C-${++req}` });
  evidence.push({ actor, method: options.method ?? 'GET', path, status: response.status, requestId: response.requestId, body: response.body, contentType: response.contentType });
  return response;
}
async function shot(name) { await page.screenshot({ path: `${artifactDir}/l10-l14-${name}.png`, fullPage: true }).catch(() => {}); }
async function ui(path, name) {
  const response = await page.goto(`${base}${path}`, { waitUntil: 'domcontentloaded', timeout: 30000 }).catch(() => null);
  await page.locator('body').waitFor({ state: 'visible', timeout: 20000 }).catch(() => {});
  await page.waitForFunction(() => document.body.innerText.trim().length > 30, null, { timeout: 25000 }).catch(() => {});
  await shot(name);
  record(/application error|internal server error|something went wrong/i.test(await page.locator('body').innerText().catch(() => '')) ? 'FAIL' : 'PASS', `${name} UI`, `document=${response?.status() ?? 'none'} uiUrl=${page.url()}`);
}
async function logout() {
  if (actor !== 'signed-out') await api(guard === 'customer' ? '/b2b/customer/logout' : '/auth/logout', { method: 'POST' }).catch(() => {});
  await context.clearCookies(); actor = 'signed-out'; guard = 'none';
}
async function login(alias, email) {
  await logout();
  const wait = Math.max(0, 13000 - (Date.now() - lastLogin)); if (wait) await sleep(wait);
  await page.goto(`${base}/login`, { waitUntil: 'domcontentloaded', timeout: 30000 });
  await page.locator('input[type="email"], input[name="email"]').first().fill(email);
  await page.locator('input[type="password"]').first().fill(password);
  await page.getByRole('button', { name: /sign in|log in/i }).click();
  await page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 30000 }).catch(() => {});
  actor = alias; guard = 'internal'; lastLogin = Date.now(); expect(`${alias} authenticated`, await api('/auth/user'), 200, `uiUrl=${page.url()}`);
}
async function loginCustomer() {
  await logout();
  await page.goto(`${base}/portal/customer/login`, { waitUntil: 'domcontentloaded', timeout: 30000 });
  await page.locator('input[type="email"], input[name="email"]').first().fill('portal@cust.test');
  await page.locator('input[type="password"]').first().fill(password);
  await page.getByRole('button', { name: /sign in|log in/i }).click();
  await page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 30000 }).catch(() => {});
  actor = 'customer-portal'; guard = 'customer'; expect('customer portal authenticated', await api('/b2b/customer/me'), 200, `uiUrl=${page.url()}`);
}
function rowShape(row) { return { nominal: row.nominal_value ?? row.spec_item?.nominal_value ?? row.inspection_spec_item?.nominal_value, lower: row.lower_limit ?? row.spec_item?.lower_limit ?? row.inspection_spec_item?.lower_limit, upper: row.upper_limit ?? row.spec_item?.upper_limit ?? row.inspection_spec_item?.upper_limit, visual: String(row.parameter_type ?? row.spec_item?.parameter_type ?? row.inspection_spec_item?.parameter_type ?? '').toLowerCase() === 'visual' }; }
async function inspect(id, fail = false) {
  const detail = await api(`/quality/inspections/${id}`); expect(`L11/L12 inspection ${fail ? 'fail' : 'pass'} detail`, detail, 200);
  const measurements = data(detail.body)?.measurements ?? [];
  if (!measurements.length) { blocked(`inspection ${id} measurements`, 'No measurement scaffold rows returned.'); return null; }
  let didFail = false;
  const patch = measurements.map((row) => {
    const shape = rowShape(row);
    if (fail && !didFail) { didFail = true; return shape.visual ? { id: row.id, is_pass: false, notes: 'LIVE-B12 intentional fail' } : { id: row.id, measured_value: String(Number(shape.lower ?? shape.nominal ?? 0) - 1), notes: 'LIVE-B12 intentional out-of-tolerance' }; }
    return shape.visual ? { id: row.id, is_pass: true, notes: 'LIVE-B12 visual pass' } : { id: row.id, measured_value: String(shape.nominal ?? '0'), notes: 'LIVE-B12 nominal measurement' };
  });
  expect(`L11/L12 inspection ${fail ? 'fail' : 'pass'} measurements`, await api(`/quality/inspections/${id}/measurements`, { method: 'PATCH', body: { measurements: patch } }), 200);
  const complete = await api(`/quality/inspections/${id}/complete`, { method: 'POST' }); expect(`L11/L12 inspection ${fail ? 'fail' : 'pass'} completion`, complete, 200); return complete;
}

try {
  await fs.mkdir(artifactDir, { recursive: true });

  // The run response returned this exact schedule row. Confirm it directly,
  // then pass the scheduler's hashed machine/mold assignment to production.
  await login('ppc', 'ppc@ogami.test');
  await ui('/production/schedule', 'l10-ppc-schedule');
  expect('L10 confirms returned schedule row', await api('/mrp/scheduler/confirm', { method: 'POST', body: { schedule_ids: [ids.schedule] } }), 200);
  expect('L10 returned schedule terminal replay', await api('/mrp/scheduler/confirm', { method: 'POST', body: { schedule_ids: [ids.schedule] } }), [200, 422]);

  await login('production', 'production@ogami.test');
  const woBefore = await api(`/production/work-orders/${ids.wo}`); expect('L11 reads scheduled planned WO', woBefore, 200);
  const wo = data(woBefore.body); observed('L11 scheduled WO assignment evidence', `wo=${ids.wo}; status=${wo?.status}; detail_machine=${wo?.machine?.id ?? wo?.machine_id ?? null}; detail_mold=${wo?.mold?.id ?? wo?.mold_id ?? null}; scheduler_machine=dGypLxpvAg; scheduler_mold=dGypLxpvAg`);
  const confirm = await api(`/production/work-orders/${ids.wo}/confirm`, { method: 'POST', body: { machine_id: 'dGypLxpvAg', mold_id: 'dGypLxpvAg' } });
  expect('L11 confirms planned WO with scheduler assignment', confirm, 200);
  expect('L11 starts planned WO', await api(`/production/work-orders/${ids.wo}/start`, { method: 'POST' }), 200);
  const cats = await api('/production/downtime-categories');
  const category = data(cats.body)?.[0]?.value ?? 'unplanned_breakdown';
  expect('L11 pauses production WO', await api(`/production/work-orders/${ids.wo}/pause`, { method: 'POST', body: { reason: 'LIVE-B12 pause probe', category } }), 200);
  expect('L11 resumes production WO', await api(`/production/work-orders/${ids.wo}/resume`, { method: 'POST' }), 200);
  const defects = await api('/production/defect-types'); const defect = rows(defects.body)[0];
  const output = await api(`/production/work-orders/${ids.wo}/outputs`, { method: 'POST', body: { good_count: 2, reject_count: 1, remarks: 'LIVE-B12 output good/reject', defects: defect ? [{ defect_type_id: defect.id, count: 1 }] : [] } });
  expect('L11 records good/reject output', output, 201); ids.output = idOf(output.body); keep('work-order-output', ids.output, 'good/reject production output');
  observed('L11 output and receipt handoff', `output=${ids.output}; good=${data(output.body)?.good_count}; reject=${data(output.body)?.reject_count}; scrap_rate=${data(output.body)?.scrap_rate}; receipt=${short(data(output.body)?.production_receipt_handoff)}`);
  const mold = await api('/mrp/molds/dGypLxpvAg'); expect('L11 reads mold shot count', mold, 200); observed('L11 mold shot reconciliation', `mold=dGypLxpvAg; shots=${data(mold.body)?.current_shots ?? data(mold.body)?.shot_count}; max=${data(mold.body)?.max_shots_before_maintenance}`);
  expect('L11 completes production WO', await api(`/production/work-orders/${ids.wo}/complete`, { method: 'POST' }), 200);
  expect('L11 closes production WO', await api(`/production/work-orders/${ids.wo}/close`, { method: 'POST' }), 200);
  expect('L11 closed WO replay', await api(`/production/work-orders/${ids.wo}/close`, { method: 'POST' }), [200, 422]);
  await ui(`/production/work-orders/${ids.wo}`, 'l11-production-closed-wo');

  // In-process fail creates the NCR through InspectionService, not by direct
  // NCR service invocation.
  await login('qc', 'qc@ogami.test');
  const inProcess = await api('/quality/inspections', { method: 'POST', body: { stage: 'in_process', product_id: ids.product, batch_quantity: 3, entity_type: 'work_order', entity_id: ids.wo, notes: 'LIVE-B12 in-process failed sample' } });
  expect('L11 creates in-process inspection', inProcess, 201); ids.inProcess = idOf(inProcess.body); keep('inspection', ids.inProcess, 'failed in-process inspection');
  if (ids.inProcess) {
    await inspect(ids.inProcess, true);
    const ncrs = await api('/quality/ncrs?per_page=100'); expect('L11 reads inspection-generated NCRs', ncrs, 200);
    const ncr = rows(ncrs.body).find((row) => row.inspection_id === ids.inProcess || row.inspection?.id === ids.inProcess);
    ids.inProcessNcr = ncr?.id; keep('ncr', ids.inProcessNcr, 'auto-NCR from in-process inspection');
    if (ids.inProcessNcr) observed('L11 in-process NCR handoff', `inspection=${ids.inProcess}; ncr=${ids.inProcessNcr}; source=${ncr.source}; status=${ncr.status}`);
    else blocked('L11 in-process auto-NCR', `No NCR linked to failed inspection ${ids.inProcess}; ncrs=${short(ncrs.body)}`);
  }

  // Outgoing AQL pass and fail use the same real output. Delivery authority is
  // only the passed output-bound inspection.
  if (ids.output) {
    const outgoing = await api('/quality/inspections', { method: 'POST', body: { stage: 'outgoing', product_id: ids.product, batch_quantity: 2, entity_type: 'work_order', entity_id: ids.wo, work_order_output_id: ids.output, notes: 'LIVE-B12 outgoing AQL pass' } });
    expect('L12 creates outgoing output-bound inspection', outgoing, 201); ids.outgoing = idOf(outgoing.body); keep('inspection', ids.outgoing, 'passed outgoing AQL inspection');
    if (ids.outgoing) { const pass = await inspect(ids.outgoing, false); expect('L12 outgoing status passed', pass, 200); const coc = await api(`/quality/inspections/${ids.outgoing}/coc`); expect('L12 outgoing CoC', coc, 200); observed('L12 CoC evidence', `inspection=${ids.outgoing}; content_type=${coc.contentType}; bytes=${coc.body ? short(coc.body) : 'stream'}`); }
    const failInspection = await api('/quality/inspections', { method: 'POST', body: { stage: 'outgoing', product_id: ids.product, batch_quantity: 2, entity_type: 'work_order', entity_id: ids.wo, work_order_output_id: ids.output, notes: 'LIVE-B12 outgoing AQL fail block' } });
    expect('L12 creates outgoing fail inspection', failInspection, 201); ids.outgoingFail = idOf(failInspection.body); keep('inspection', ids.outgoingFail, 'failed outgoing QC block evidence');
    if (ids.outgoingFail) {
      await inspect(ids.outgoingFail, true);
      await login('warehouse', 'warehouse@ogami.test');
      const soDetail = await api(`/crm/sales-orders/${ids.so}`); // expected row-scope denial is itself captured
      const itemId = data(soDetail.body)?.items?.[0]?.id;
      const deniedDelivery = await api('/supply-chain/deliveries', { method: 'POST', body: { sales_order_id: ids.so, scheduled_date: '2026-10-01', items: [{ sales_order_item_id: itemId, quantity: '1.00', inspection_id: ids.outgoingFail }] } });
      if (deniedDelivery.status === 403) blocked('L12 failed outgoing delivery block source', 'Warehouse lacks delivery-create permission, so direct QC-block delivery creation could not be attempted by its owner.');
      else expect('L12 failed outgoing QC blocks delivery', deniedDelivery, 422);
    }
  } else blocked('L12 outgoing AQL/CoC', 'No output row was created by the planned WO.');

  // Complaint source cleanup and the two successful disposition branches. The
  // 8D gate is exercised rather than bypassed before complaint close.
  await login('crm', 'crm@ogami.test');
  for (const complaintId of [ids.scrapComplaint, ids.reworkComplaint]) {
    const eightD = await api(`/crm/complaints/${complaintId}/8d`, { method: 'PATCH', body: { d1_team: 'LIVE-B12 team', d2_problem: 'Customer-reported nonconformance', d3_containment: 'Quarantine affected quantity', d4_root_cause: 'Process variation review', d5_corrective_action: 'Correct process window', d6_verification: 'Verify next lot', d7_prevention: 'Update control plan', d8_recognition: 'Customer response recorded' } });
    expect(`L13 complaint ${complaintId} 8D update`, eightD, 200);
    expect(`L13 complaint ${complaintId} 8D finalize`, await api(`/crm/complaints/${complaintId}/8d/finalize`, { method: 'POST' }), 200);
    expect(`L13 complaint ${complaintId} resolve`, await api(`/crm/complaints/${complaintId}/resolve`, { method: 'POST' }), 200);
    expect(`L13 complaint ${complaintId} close`, await api(`/crm/complaints/${complaintId}/close`, { method: 'POST' }), 200);
  }

  // Retry the two previously 500ing complaint-source writes once with the same
  // valid product/customer but no SO, to isolate source-link validation from
  // downstream O2C state. A repeat 500 is a product finding, not a fixture gap.
  const retryComplaints = [];
  for (const disposition of ['use_as_is', 'return_to_supplier']) {
    const complaint = await api('/crm/complaints', { method: 'POST', body: { customer_id: ids.customer, product_id: ids.product, received_date: '2026-09-14', severity: 'low', description: `LIVE-B12 retry complaint ${disposition} ${stamp}`, affected_quantity: 1 } });
    if (complaint.status === 201) { expect(`L13 retry complaint ${disposition}`, complaint, 201); const cid = idOf(complaint.body); const nid = data(complaint.body)?.ncr_id ?? data(complaint.body)?.ncr?.id; retryComplaints.push({ cid, nid, disposition }); keep('complaint', cid, `retry ${disposition}`); keep('ncr', nid, `retry ${disposition}`); }
    else expect(`L13 retry complaint ${disposition} no-SO`, complaint, 201, 'repeat of prior generic 500');
  }
  await login('qc', 'qc@ogami.test');
  for (const target of retryComplaints) {
    if (!target.nid) { blocked(`L13 ${target.disposition} retry NCR`, 'Retry complaint did not return an NCR hash.'); continue; }
    const corrective = await api(`/quality/ncrs/${target.nid}/actions`, { method: 'POST', body: { action_type: 'corrective', description: `LIVE-B12 corrective ${target.disposition}`, due_date: '2026-09-15' } });
    const preventive = await api(`/quality/ncrs/${target.nid}/actions`, { method: 'POST', body: { action_type: 'preventive', description: `LIVE-B12 preventive ${target.disposition}`, due_date: '2026-09-15' } });
    expect(`L13 ${target.disposition} corrective`, corrective, 201); expect(`L13 ${target.disposition} preventive`, preventive, 201);
    expect(`L13 ${target.disposition} disposition`, await api(`/quality/ncrs/${target.nid}/disposition`, { method: 'PATCH', body: { disposition: target.disposition, root_cause: 'LIVE-B12 source investigation', corrective_action: `LIVE-B12 ${target.disposition} response` } }), 200);
    expect(`L13 ${target.disposition} close`, await api(`/quality/ncrs/${target.nid}/close`, { method: 'POST' }), 200);
    for (const actionId of [idOf(corrective.body), idOf(preventive.body)].filter(Boolean)) expect(`L13 ${target.disposition} CAPA verify`, await api(`/quality/ncrs/${target.nid}/actions/${actionId}/verify`, { method: 'PATCH', body: { effectiveness_status: 'effective', notes: 'LIVE-B12 effectiveness evidence' } }), 200);
  }
  const pareto = await api('/quality/analytics/defect-pareto?from=2026-01-01&to=2026-12-31'); expect('L13 Pareto analytics', pareto, 200);
  const ncrFeed = await api('/notifications?per_page=100'); expect('L13 notification feed', ncrFeed, 200); observed('L13 notification/action links', `rows=${rows(ncrFeed.body).length}; captured direct action links in NCR auto-create implementation response where present`);

  // L14 maintenance execution and interruption evidence. Condition readings are
  // intentionally hidden by scope cut; no fake health reading is written.
  await login('maintenance', 'maintenance@ogami.test');
  await ui('/maintenance/work-orders', 'l14-maintenance-work-orders');
  const opts = await api('/maintenance/work-orders/options'); const assignees = await api('/maintenance/work-orders/assignees');
  const source = data(opts.body)?.maintainables?.[0] ?? data(opts.body)?.machines?.[0];
  const assignee = rows(assignees.body)[0]?.id;
  if (assignees.status !== 200 || !source?.id || !assignee) blocked('L14 corrective MWO assignment source', `assignees=${assignees.status}; source=${short(opts.body)}; no direct service MWO manufactured.`);
  else {
    const mwo = await api('/maintenance/work-orders', { method: 'POST', body: { maintainable_type: source.type ?? 'machine', maintainable_id: source.id, type: 'corrective', priority: 'high', description: `LIVE-B12 corrective interruption MWO ${stamp}` } });
    expect('L14 creates corrective MWO', mwo, 201); ids.mwo = idOf(mwo.body); keep('maintenance-work-order', ids.mwo, 'corrective interruption evidence');
    if (ids.mwo) { expect('L14 assigns MWO', await api(`/maintenance/work-orders/${ids.mwo}/assign`, { method: 'PATCH', body: { employee_id: assignee } }), 200); expect('L14 starts MWO', await api(`/maintenance/work-orders/${ids.mwo}/start`, { method: 'PATCH' }), 200); expect('L14 logs MWO', await api(`/maintenance/work-orders/${ids.mwo}/logs`, { method: 'POST', body: { description: 'LIVE-B12 machine breakdown diagnosis' } }), 201); expect('L14 completes MWO', await api(`/maintenance/work-orders/${ids.mwo}/complete`, { method: 'PATCH', body: { remarks: 'LIVE-B12 completed correction', downtime_minutes: 22 } }), 200); expect('L14 MWO terminal replay', await api(`/maintenance/work-orders/${ids.mwo}/complete`, { method: 'PATCH', body: { downtime_minutes: 22 } }), 422); }
  }
  const health = await api('/maintenance/condition-readings/health-snapshot');
  if (health.status === 404) blocked('L14 condition-reading threshold/MWO automation', 'Condition-reading routes are scope-cut and absent; no hidden condition-reading assumption was made.'); else expect('L14 condition-reading route', health, 200);
  expect('L14 downtime analytics', await api('/maintenance/downtime-analytics/summary'), 200);

  await login('employee', 'employee@ogami.test');
  expect('L14 wrong-role production lifecycle denial', await api(`/production/work-orders/${ids.wo}/start`, { method: 'POST' }), 403);
  expect('L14 wrong-role NCR terminal denial', await api(`/quality/ncrs/${ids.inProcessNcr ?? 'invalid'}/close`, { method: 'POST' }), 403);
} catch (error) {
  record('FAIL', 'L10-L14 continuation harness', error instanceof Error ? error.stack ?? error.message : String(error));
  await shot('continuation-failure').catch(() => {});
} finally {
  await logout().catch(() => {});
  await browser.close();
  await fs.writeFile(`${artifactDir}/l10-l14-continuation-results.json`, JSON.stringify({ generated_at: new Date().toISOString(), ids, residuals, results, evidence }, null, 2));
}
console.log(`COUNTS ${JSON.stringify(results.reduce((out, row) => ({ ...out, [row.status]: (out[row.status] ?? 0) + 1 }), {}))}`);
console.log(`IDS ${JSON.stringify(ids)}`);
