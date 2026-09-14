import { firefox } from '../../spa/node_modules/playwright/index.mjs';
import fs from 'node:fs/promises';

const base = 'http://localhost';
const artifactDir = '/home/kwat0g/Desktop/kwatog/audit/live-2026-09-14/artifacts';
const stamp = Date.now().toString().slice(-8);
const password = 'password';
const results = [];
const evidence = [];
const ids = {};
const residuals = [];
let actor = 'signed-out';
let guard = 'none';
let lastLogin = 0;
let requestNo = 0;

const browser = await firefox.launch({ headless: true });
const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
const page = await context.newPage();

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const dataOf = (body) => body?.data ?? body;
const rowsOf = (body) => {
  const value = dataOf(body);
  if (Array.isArray(value)) return value;
  if (Array.isArray(value?.data)) return value.data;
  return [];
};
const idOf = (body) => dataOf(body)?.id ?? dataOf(body)?.hash_id ?? null;
const brief = (value) => JSON.stringify(value ?? '').slice(0, 2200);
const numericIdLeak = (value) => /"(?:id|\w+_id|reference_id)"\s*:\s*\d+(?:\.\d+)?(?:[,}])/i.test(JSON.stringify(value));

function record(status, name, detail = '') {
  results.push({ status, actor, name, detail });
  console.log(`${status} ${actor} ${name}${detail ? ` :: ${detail}` : ''}`);
}
function expect(name, response, statuses, detail = '') {
  const accepted = Array.isArray(statuses) ? statuses : [statuses];
  const ok = accepted.includes(response.status);
  record(ok ? 'PASS' : 'FAIL', name, `HTTP ${response.status}; expected ${accepted.join('/')} ${detail}; body=${brief(response.body)}`);
  return ok;
}
function assert(name, condition, detail = '') {
  record(condition ? 'PASS' : 'FAIL', name, detail || (condition ? 'condition true' : 'condition false'));
  return condition;
}
function blocked(name, detail) { record('BLOCKED', name, detail); }
function observed(name, detail) { record('OBSERVED', name, detail); }
function retain(type, id, detail = '') { if (id) residuals.push({ type, id, detail }); return id; }

async function api(path, options = {}) {
  const result = await page.evaluate(async ({ path, options, requestId }) => {
    const sanitize = (value) => {
      if (Array.isArray(value)) return value.map(sanitize);
      if (!value || typeof value !== 'object') return value;
      return Object.fromEntries(Object.entries(value).map(([key, item]) => [
        key, /temp_password|temporary_password|password_hash/i.test(key) ? '[REDACTED]' : sanitize(item),
      ]));
    };
    const xsrf = document.cookie.split('; ').find((cookie) => cookie.startsWith('XSRF-TOKEN='))?.slice('XSRF-TOKEN='.length);
    const headers = {
      Accept: 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      'X-Request-ID': requestId,
      ...(xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } : {}),
      ...(options.multipart ? {} : options.body === undefined ? {} : { 'Content-Type': 'application/json' }),
      ...(options.headers ?? {}),
    };
    let body;
    if (options.multipart) {
      const form = new FormData();
      for (const [key, value] of Object.entries(options.fields ?? {})) form.append(key, String(value));
      form.append(options.fileField ?? 'file', new Blob([new Uint8Array(options.bytes ?? [37, 80, 68, 70, 45, 49, 46, 52, 10, 97, 117, 100, 105, 116])], { type: options.mime ?? 'application/pdf' }), options.filename ?? 'live-b12-proof.pdf');
      body = form;
    } else if (options.body !== undefined) body = JSON.stringify(options.body);
    const target = path.startsWith('/api/') ? path : `/api/v1${path}`;
    const response = await fetch(target, { method: options.method ?? 'GET', credentials: 'include', headers, body });
    const text = await response.text();
    let parsed;
    try { parsed = JSON.parse(text); } catch { parsed = text; }
    return {
      status: response.status,
      body: sanitize(parsed),
      contentType: response.headers.get('content-type'),
      disposition: response.headers.get('content-disposition'),
      requestId: response.headers.get('x-request-id') ?? response.headers.get('x-correlation-id') ?? requestId,
    };
  }, { path, options, requestId: `LIVE-B12-${++requestNo}` });
  evidence.push({ actor, method: options.method ?? 'GET', path, status: result.status, requestId: result.requestId, body: result.body, contentType: result.contentType });
  return result;
}

async function file(path, accept = 'application/pdf') {
  const result = await page.evaluate(async ({ path, accept }) => {
    const xsrf = document.cookie.split('; ').find((cookie) => cookie.startsWith('XSRF-TOKEN='))?.slice('XSRF-TOKEN='.length);
    const response = await fetch(`/api/v1${path}`, { credentials: 'include', headers: { Accept: accept, 'X-Requested-With': 'XMLHttpRequest', ...(xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } : {}) } });
    return { status: response.status, contentType: response.headers.get('content-type'), disposition: response.headers.get('content-disposition'), bytes: (await response.arrayBuffer()).byteLength };
  }, { path, accept });
  evidence.push({ actor, method: 'GET', path, status: result.status, file: result });
  return result;
}

async function shot(name) {
  await page.screenshot({ path: `${artifactDir}/l10-l14-${name}.png`, fullPage: true }).catch(() => {});
}

async function ui(path, name) {
  const response = await page.goto(`${base}${path}`, { waitUntil: 'domcontentloaded', timeout: 30000 }).catch(() => null);
  await page.locator('body').waitFor({ state: 'visible', timeout: 20000 }).catch(() => {});
  await page.waitForFunction(() => document.body.innerText.trim().length > 30, null, { timeout: 25000 }).catch(() => {});
  const text = await page.locator('body').innerText().catch(() => '');
  await shot(name);
  const bad = /application error|internal server error|something went wrong/i.test(text);
  record(bad ? 'FAIL' : 'PASS', `${name} UI`, `document=${response?.status() ?? 'none'} uiUrl=${page.url()}`);
  return text;
}

async function logout() {
  if (actor !== 'signed-out') {
    if (guard === 'customer') await api('/b2b/customer/logout', { method: 'POST' }).catch(() => {});
    else if (guard === 'supplier') await api('/b2b/supplier/logout', { method: 'POST' }).catch(() => {});
    else await api('/auth/logout', { method: 'POST' }).catch(() => {});
  }
  await context.clearCookies();
  actor = 'signed-out';
  guard = 'none';
}

async function login(alias, email) {
  await logout();
  const wait = Math.max(0, 13000 - (Date.now() - lastLogin));
  if (wait) await sleep(wait);
  await page.goto(`${base}/login`, { waitUntil: 'domcontentloaded', timeout: 30000 });
  await page.locator('input[type="email"], input[name="email"]').first().fill(email);
  await page.locator('input[type="password"]').first().fill(password);
  await page.getByRole('button', { name: /sign in|log in/i }).click();
  await page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 30000 }).catch(() => {});
  actor = alias;
  guard = 'internal';
  lastLogin = Date.now();
  const identity = await api('/auth/user');
  expect(`${alias} authenticated`, identity, 200, `uiUrl=${page.url()}`);
  return identity;
}

async function loginCustomerPortal() {
  await logout();
  await page.goto(`${base}/portal/customer/login`, { waitUntil: 'domcontentloaded', timeout: 30000 });
  const email = page.locator('input[type="email"], input[name="email"]').first();
  const pass = page.locator('input[type="password"]').first();
  await email.fill('portal@cust.test');
  await pass.fill(password);
  await page.getByRole('button', { name: /sign in|log in/i }).click();
  await page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 30000 }).catch(() => {});
  actor = 'customer-portal';
  guard = 'customer';
  const identity = await api('/b2b/customer/me');
  expect('customer portal authenticated', identity, 200, `uiUrl=${page.url()}`);
  return identity;
}

async function poll(path, predicate, timeoutMs = 90000) {
  const deadline = Date.now() + timeoutMs;
  let latest = null;
  while (Date.now() < deadline) {
    latest = await api(path);
    if (predicate(latest)) return latest;
    await sleep(2500);
  }
  return latest;
}

function nominalRow(row) {
  const nominal = row.nominal_value ?? row.spec_item?.nominal_value ?? row.inspection_spec_item?.nominal_value ?? row.parameter?.nominal_value;
  const lower = row.lower_limit ?? row.spec_item?.lower_limit ?? row.inspection_spec_item?.lower_limit;
  const upper = row.upper_limit ?? row.spec_item?.upper_limit ?? row.inspection_spec_item?.upper_limit;
  return { nominal, lower, upper };
}

async function completeInspection(id, mode = 'pass') {
  const detail = await api(`/quality/inspections/${id}`);
  const measurements = dataOf(detail.body)?.measurements ?? [];
  if (!measurements.length) return { detail, completed: null, measurements };
  let failed = false;
  const rows = measurements.map((row, index) => {
    const shape = nominalRow(row);
    const isVisual = String(row.parameter_type ?? row.spec_item?.parameter_type ?? row.inspection_spec_item?.parameter_type ?? '').toLowerCase() === 'visual';
    if (mode === 'fail' && !failed) {
      failed = true;
      return isVisual ? { id: row.id, is_pass: false, notes: 'LIVE-B12 intentional failed visual result' } : { id: row.id, measured_value: String(Number(shape.lower ?? shape.nominal ?? 0) - 1), notes: 'LIVE-B12 intentional out-of-tolerance result' };
    }
    return isVisual ? { id: row.id, is_pass: true, notes: 'LIVE-B12 visual pass' } : { id: row.id, measured_value: shape.nominal ?? '0', notes: 'LIVE-B12 nominal actual measurement' };
  });
  const measurementsResponse = await api(`/quality/inspections/${id}/measurements`, { method: 'PATCH', body: { measurements: rows } });
  expect(`inspection ${mode} measurements`, measurementsResponse, 200);
  const completed = await api(`/quality/inspections/${id}/complete`, { method: 'POST' });
  expect(`inspection ${mode} complete`, completed, 200);
  return { detail, completed, measurements };
}

try {
  await fs.mkdir(artifactDir, { recursive: true });

  // L10: CRM source reads, SO draft/edit/confirm, and the separate customer
  // negotiation path. Existing masters are reused; no product or agreement is
  // fabricated when the seeded CRM role cannot manage those resources.
  await login('crm', 'crm@ogami.test');
  await ui('/crm/sales-orders', 'l10-crm-sales-orders');
  const [customers, products, agreements] = await Promise.all([
    api('/crm/customers?per_page=100'),
    api('/crm/products?per_page=100'),
    api('/crm/price-agreements?per_page=100'),
  ]);
  expect('L10 CRM customer source list', customers, 200);
  expect('L10 CRM product source list', products, 200);
  expect('L10 CRM price-agreement source list', agreements, 200);
  const activeCustomers = rowsOf(customers.body).filter((row) => row.is_active !== false);
  const activeProducts = rowsOf(products.body).filter((row) => row.is_active !== false);
  const agreement = rowsOf(agreements.body).find((row) => row.is_active !== false && row.status !== 'inactive' && activeCustomers.some((c) => c.id === (row.customer_id ?? row.customer?.id)) && activeProducts.some((p) => p.id === (row.product_id ?? row.product?.id)));
  const customer = activeCustomers.find((row) => row.id === (agreement?.customer_id ?? agreement?.customer?.id));
  const product = activeProducts.find((row) => row.id === (agreement?.product_id ?? agreement?.product?.id));
  if (!customer?.id || !product?.id) {
    blocked('L10 CRM disposable SO source', `No active customer/product/price-agreement triple; agreement=${brief(agreement)}`);
  } else {
    ids.customer = customer.id;
    ids.product = product.id;
    observed('L10 CRM source hashes and agreement', `customer=${ids.customer}; product=${ids.product}; agreement=${idOf(agreement)}; agreement=${brief(agreement)}`);
    const date = new Date().toISOString().slice(0, 10);
    const deliveryDate = new Date(Date.now() + 14 * 86400000).toISOString().slice(0, 10);
    const soPayload = { customer_id: ids.customer, date, payment_terms_days: 30, delivery_terms: 'LIVE-B12 disposable O2C', notes: `LIVE-B12 L10 ${stamp}`, items: [{ product_id: ids.product, quantity: '3.00', delivery_date: deliveryDate }] };
    const so = await api('/crm/sales-orders', { method: 'POST', body: soPayload });
    expect('L10 CRM creates disposable confirmed SO', so, 201);
    ids.so = idOf(so.body);
    retain('sales-order', ids.so, 'confirmed O2C source; terminal evidence retained');
    if (ids.so) {
      const edit = await api(`/crm/sales-orders/${ids.so}`, { method: 'PUT', body: { ...soPayload, notes: `LIVE-B12 L10 edited ${stamp}` } });
      expect('L10 CRM edits draft SO', edit, 200);
      const confirm = await api(`/crm/sales-orders/${ids.so}/confirm`, { method: 'POST', body: {} });
      expect('L10 CRM confirms SO', confirm, 200);
      expect('L10 SO confirmation terminal replay', await api(`/crm/sales-orders/${ids.so}/confirm`, { method: 'POST', body: {} }), 422);
      const detail = await api(`/crm/sales-orders/${ids.so}`);
      expect('L10 confirmed SO detail', detail, 200);
      assert('L10 SO has no raw integer references', !numericIdLeak(detail.body), brief(detail.body));
      observed('L10 SO state and total', `so=${ids.so}; number=${dataOf(detail.body)?.so_number}; status=${dataOf(detail.body)?.status}; total=${dataOf(detail.body)?.total_amount}`);
    }

    // Customer-confirmation branch uses a second disposable order because a
    // confirmed SO is terminal for the CRM confirmation action.
    const portalSo = await api('/crm/sales-orders', { method: 'POST', body: { ...soPayload, notes: `LIVE-B12 customer confirmation ${stamp}`, items: [{ product_id: ids.product, quantity: '1.00', delivery_date: deliveryDate }] } });
    expect('L10 CRM creates customer-confirmation SO', portalSo, 201);
    ids.portalSo = idOf(portalSo.body);
    retain('sales-order', ids.portalSo, 'customer portal negotiation evidence');
    if (ids.portalSo) {
      expect('L10 CRM edits customer-confirmation SO', await api(`/crm/sales-orders/${ids.portalSo}`, { method: 'PUT', body: { ...soPayload, notes: `LIVE-B12 customer confirmation edited ${stamp}`, items: [{ product_id: ids.product, quantity: '1.00', delivery_date: deliveryDate }] } }), 200);
      const release = await api(`/crm/sales-orders/${ids.portalSo}/request-customer-confirmation`, { method: 'POST', body: {} });
      expect('L10 CRM requests customer confirmation', release, 200);
      if (release.status === 200) {
        await loginCustomerPortal();
        await ui('/portal/customer/orders', 'l10-customer-orders');
        const portalOrder = await api(`/b2b/customer/orders/${ids.portalSo}`);
        expect('L10 customer portal reads own SO', portalOrder, 200);
        const response = await api(`/b2b/customer/orders/${ids.portalSo}/respond`, { method: 'POST', body: { type: 'accept', notes: 'LIVE-B12 customer acceptance' } });
        expect('L10 customer portal accepts SO', response, 201);
        ids.soResponse = idOf(response.body);
        expect('L10 customer SO response exact replay', await api(`/b2b/customer/orders/${ids.portalSo}/respond`, { method: 'POST', body: { type: 'accept', notes: 'LIVE-B12 exact replay' } }), [409, 422]);
        await login('crm', 'crm@ogami.test');
        const responses = await api(`/crm/sales-orders/${ids.portalSo}/responses`);
        expect('L10 CRM reads customer response', responses, 200);
        const responseId = ids.soResponse ?? rowsOf(responses.body)[0]?.id;
        if (responseId) expect('L10 CRM resolves accepted customer response', await api(`/crm/sales-order-responses/${responseId}/accept`, { method: 'PATCH', body: {} }), 200);
      }
    }
  }

  // L10 MRP handoff: the confirmed disposable SO is the only source passed to
  // the queue. The worker owns plan creation; a manual all-SO run is avoided.
  if (ids.so) {
    await login('ppc', 'ppc@ogami.test');
    await ui('/mrp/plans', 'l10-ppc-plans');
    const plan = await poll(`/mrp/sales-orders/${ids.so}/mrp-plan`, (r) => r.status === 200 && Boolean(dataOf(r.body)), 100000);
    if (plan?.status !== 200) {
      blocked('L10 queued MRP plan', `Confirmed SO ${ids.so} did not produce a readable plan; response=${brief(plan?.body)}`);
    } else {
      ids.plan = idOf(plan.body);
      retain('mrp-plan', ids.plan, 'queued plan for disposable confirmed SO');
      const planData = dataOf(plan.body);
      observed('L10 MRP plan handoff', `plan=${ids.plan}; shortages=${planData?.shortages_found}; draft_wos=${planData?.draft_wo_count}; work_orders=${brief(planData?.work_orders)}`);
      assert('L10 MRP plan links confirmed SO', planData?.sales_order?.id === ids.so || planData?.generation?.source_id === ids.so || JSON.stringify(planData).includes(ids.so), brief(planData));
      assert('L10 MRP plan has no raw integer references', !numericIdLeak(planData), brief(planData));
      const workRows = Array.isArray(planData?.work_orders) ? planData.work_orders : (Array.isArray(planData?.draft_work_orders) ? planData.draft_work_orders : []);
      ids.wo = workRows.map(idOf).find(Boolean) ?? null;
      if (ids.wo) retain('work-order', ids.wo, 'MRP-generated disposable O2C WO');
      if (!ids.wo) blocked('L10/L11 MRP draft WO source', `Plan did not expose a draft work-order hash; plan=${brief(planData)}`);
    }
  } else {
    blocked('L10/L11 MRP handoff', 'No confirmed disposable SO was created.');
  }

  // Schedule the single disposable MRP-generated WO, if the queue produced it.
  if (ids.wo) {
    const run = await api('/mrp/scheduler/run', { method: 'POST', body: { work_order_ids: [ids.wo] } });
    expect('L10 PPC runs scoped scheduler', run, 200);
    const snapshot = await api('/mrp/scheduler/snapshot?from=2026-09-14&to=2026-10-31');
    expect('L10 PPC reads scheduler snapshot', snapshot, 200);
    const schedules = rowsOf(snapshot.body);
    const schedule = schedules.find((row) => row.work_order_id === ids.wo || row.work_order?.id === ids.wo);
    if (!schedule?.id) blocked('L10 scheduler confirmation source', `No schedule row linked to WO ${ids.wo}; snapshot=${brief(snapshot.body)}`);
    else {
      ids.schedule = schedule.id;
      observed('L10 scheduler source', `schedule=${ids.schedule}; machine=${schedule.machine_id ?? schedule.machine?.id}; mold=${schedule.mold_id ?? schedule.mold?.id}`);
      expect('L10 PPC scheduler reorder', await api(`/mrp/scheduler/${ids.schedule}/reorder`, { method: 'PATCH', body: { priority_order: 1 } }), 200);
      if (schedule.machine_id && schedule.mold_id) expect('L10 PPC scheduler reassign', await api(`/mrp/scheduler/${ids.schedule}/reassign`, { method: 'PATCH', body: { machine_id: schedule.machine_id, mold_id: schedule.mold_id } }), 200);
      else blocked('L10 scheduler reassign', 'Schedule did not expose both machine and mold hashes.');
      const confirmedSchedule = await api('/mrp/scheduler/confirm', { method: 'POST', body: { schedule_ids: [ids.schedule] } });
      expect('L10 PPC confirms schedule', confirmedSchedule, 200);
      expect('L10 scheduler confirmation replay', await api('/mrp/scheduler/confirm', { method: 'POST', body: { schedule_ids: [ids.schedule] } }), [200, 422]);
    }
  }

  // L11 planned WO execution and in-process QC.
  if (ids.wo) {
    await login('production', 'production@ogami.test');
    await ui(`/production/work-orders/${ids.wo}`, 'l11-production-work-order');
    const woBefore = await api(`/production/work-orders/${ids.wo}`);
    expect('L11 production reads planned WO', woBefore, 200);
    const woData = dataOf(woBefore.body);
    observed('L11 planned WO assignment', `wo=${ids.wo}; status=${woData?.status}; product=${woData?.product?.id ?? woData?.product_id}; machine=${woData?.machine?.id ?? woData?.machine_id}; mold=${woData?.mold?.id ?? woData?.mold_id}; target=${woData?.quantity_target}`);
    if (!woData?.machine_id && !woData?.machine?.id) blocked('L11 production execution', 'MRP-generated WO has no machine assignment after scheduling; no direct WO was created.');
    else {
      const confirmed = await api(`/production/work-orders/${ids.wo}/confirm`, { method: 'POST', body: { machine_id: woData.machine?.id ?? woData.machine_id, mold_id: woData.mold?.id ?? woData.mold_id } });
      expect('L11 production confirms planned WO', confirmed, 200);
      expect('L11 production starts planned WO', await api(`/production/work-orders/${ids.wo}/start`, { method: 'POST' }), 200);
      const cats = await api('/production/downtime-categories');
      const category = dataOf(cats.body)?.[0]?.value ?? 'unplanned_breakdown';
      expect('L11 production pauses planned WO', await api(`/production/work-orders/${ids.wo}/pause`, { method: 'POST', body: { reason: 'LIVE-B12 interruption probe', category } }), 200);
      expect('L11 production resumes planned WO', await api(`/production/work-orders/${ids.wo}/resume`, { method: 'POST' }), 200);
      const defects = await api('/production/defect-types');
      const defect = rowsOf(defects.body)[0];
      const output = await api(`/production/work-orders/${ids.wo}/outputs`, { method: 'POST', body: { good_count: 2, reject_count: 1, remarks: 'LIVE-B12 good/reject output', defects: defect ? [{ defect_type_id: defect.id, count: 1 }] : [] } });
      expect('L11 production records good/reject output', output, 201);
      ids.output = idOf(output.body);
      retain('work-order-output', ids.output, 'good/reject output for outgoing inspection');
      const outputData = dataOf(output.body);
      observed('L11 output reconciliation', `output=${ids.output}; good=${outputData?.good_count}; reject=${outputData?.reject_count}; scrap=${outputData?.scrap_rate}; receipt=${brief(outputData?.production_receipt_handoff)}`);
      const moldId = woData.mold?.id ?? woData.mold_id;
      if (moldId) {
        const mold = await api(`/mrp/molds/${moldId}`);
        expect('L11 mold shot read after output', mold, 200);
        observed('L11 mold shot count', `mold=${moldId}; shots=${dataOf(mold.body)?.current_shots ?? dataOf(mold.body)?.shot_count}; max=${dataOf(mold.body)?.max_shots_before_maintenance ?? dataOf(mold.body)?.lifetime_max_shots}`);
      }
      expect('L11 production completes WO', await api(`/production/work-orders/${ids.wo}/complete`, { method: 'POST' }), 200);
      const close = await api(`/production/work-orders/${ids.wo}/close`, { method: 'POST' });
      expect('L11 production closes WO', close, 200);
      expect('L11 production close terminal replay', await api(`/production/work-orders/${ids.wo}/close`, { method: 'POST' }), [200, 422]);
      const woAfter = await api(`/production/work-orders/${ids.wo}/chain`);
      expect('L11 production chain detail', woAfter, 200);

      // In-process failed inspection is created against the actual planned WO.
      await login('qc', 'qc@ogami.test');
      await ui('/quality/inspections', 'l11-qc-inspections');
      const inProcess = await api('/quality/inspections', { method: 'POST', body: { stage: 'in_process', product_id: woData.product?.id ?? woData.product_id, batch_quantity: 3, entity_type: 'work_order', entity_id: ids.wo, notes: 'LIVE-B12 intentional in-process fail' } });
      expect('L11 QC creates in-process inspection', inProcess, 201);
      ids.inProcessInspection = idOf(inProcess.body);
      retain('inspection', ids.inProcessInspection, 'failed in-process inspection and auto-NCR evidence');
      if (ids.inProcessInspection) {
        const completed = await completeInspection(ids.inProcessInspection, 'fail');
        if (!completed.measurements.length) blocked('L11 in-process measured failure', 'Inspection had no measurement rows.');
        const ncrRows = rowsOf((await api('/quality/ncrs?per_page=100')).body);
        const ncr = ncrRows.find((row) => row.inspection_id === ids.inProcessInspection || row.inspection?.id === ids.inProcessInspection);
        ids.inProcessNcr = ncr?.id ?? dataOf(completed.completed?.body)?.ncr?.id;
        if (ids.inProcessNcr) retain('ncr', ids.inProcessNcr, 'auto-created from failed in-process inspection');
        else blocked('L11 failed in-process inspection NCR handoff', `No NCR linked to inspection ${ids.inProcessInspection}; ncrs=${brief(ncrRows)}`);
      }
    }
  } else {
    blocked('L11 planned WO execution and in-process QC', 'No MRP-generated WO was available; no direct service WO was manufactured.');
  }

  // L12 outgoing pass/fail, CoC, delivery, portal confirmation, invoice and AR.
  if (ids.output && ids.so) {
    await login('qc', 'qc@ogami.test');
    const outputDetail = await api(`/production/work-orders/${ids.wo}/outputs`);
    const outputRow = rowsOf(outputDetail.body).find((row) => row.id === ids.output) ?? dataOf(outputDetail.body)?.[0];
    const productId = outputRow?.product?.id ?? outputRow?.product_id;
    const outgoing = await api('/quality/inspections', { method: 'POST', body: { stage: 'outgoing', product_id: productId, batch_quantity: Number(outputRow?.good_count ?? 2), entity_type: 'work_order', entity_id: ids.wo, work_order_output_id: ids.output, notes: 'LIVE-B12 outgoing AQL pass' } });
    expect('L12 QC creates output-bound outgoing inspection', outgoing, 201);
    ids.outgoingInspection = idOf(outgoing.body);
    retain('inspection', ids.outgoingInspection, 'passed outgoing AQL inspection for delivery');
    if (ids.outgoingInspection) {
      const passed = await completeInspection(ids.outgoingInspection, 'pass');
      const passBody = dataOf(passed.completed?.body);
      assert('L12 outgoing inspection passed', passBody?.status === 'passed', brief(passBody));
      const coc = await file(`/quality/inspections/${ids.outgoingInspection}/coc`);
      expect('L12 outgoing CoC document', coc, 200);
      assert('L12 outgoing CoC PDF MIME', String(coc.contentType).includes('pdf'), brief(coc));

      // A separate outgoing inspection against the same physical output proves
      // the fail path without using a failed inspection as delivery authority.
      const outgoingFail = await api('/quality/inspections', { method: 'POST', body: { stage: 'outgoing', product_id: productId, batch_quantity: Number(outputRow?.good_count ?? 2), entity_type: 'work_order', entity_id: ids.wo, work_order_output_id: ids.output, notes: 'LIVE-B12 outgoing AQL fail/block probe' } });
      expect('L12 QC creates outgoing fail probe', outgoingFail, 201);
      ids.outgoingFailInspection = idOf(outgoingFail.body);
      retain('inspection', ids.outgoingFailInspection, 'failed outgoing inspection proving delivery block');
      if (ids.outgoingFailInspection) {
        await completeInspection(ids.outgoingFailInspection, 'fail');
        const deliveryProbe = await api('/supply-chain/deliveries', { method: 'POST', body: { sales_order_id: ids.so, scheduled_date: new Date(Date.now() + 7 * 86400000).toISOString().slice(0, 10), notes: 'LIVE-B12 failed-QC delivery block probe', items: [{ sales_order_item_id: dataOf((await api(`/crm/sales-orders/${ids.so}`)).body)?.items?.[0]?.id, quantity: '1.00', inspection_id: ids.outgoingFailInspection }] } });
        expect('L12 failed outgoing QC blocks delivery', deliveryProbe, 422);
      }
    }

    // Resolve which legitimate business actor can create a delivery. The
    // requested fixture is BLOCKED if the seeded role permissions do not expose
    // this write; no admin bypass is used.
    let deliveryActor = null;
    for (const candidate of [['impex', 'impex@ogami.test'], ['warehouse', 'warehouse@ogami.test']]) {
      await login(candidate[0], candidate[1]);
      const probe = await api('/supply-chain/deliveries/inspection-options');
      if (probe.status === 200) { deliveryActor = candidate; break; }
      observed(`L12 ${candidate[0]} delivery-create permission probe`, `HTTP ${probe.status}; body=${brief(probe.body)}`);
    }
    if (!deliveryActor) {
      blocked('L12 delivery draft/proof/status path', 'No requested warehouse/ImpEx actor has supply_chain.deliveries.create; no seeded delivery was mutated and no admin bypass was used.');
    } else {
      actor = deliveryActor[0];
      const soDetail = await api(`/crm/sales-orders/${ids.so}`);
      const soItem = dataOf(soDetail.body)?.items?.[0];
      const delivery = await api('/supply-chain/deliveries', { method: 'POST', body: { sales_order_id: ids.so, scheduled_date: new Date(Date.now() + 7 * 86400000).toISOString().slice(0, 10), notes: `LIVE-B12 partial delivery ${stamp}`, items: [{ sales_order_item_id: soItem?.id, quantity: '1.00', inspection_id: ids.outgoingInspection }] } });
      expect('L12 creates disposable delivery draft', delivery, 201);
      ids.delivery = idOf(delivery.body);
      retain('delivery', ids.delivery, 'partial delivery through confirmed customer SO');
      if (ids.delivery) {
        await ui(`/supply-chain/deliveries/${ids.delivery}`, 'l12-delivery-draft');
        expect('L12 delivery loading', await api(`/supply-chain/deliveries/${ids.delivery}/status`, { method: 'PATCH', body: { status: 'loading' } }), 200);
        expect('L12 delivery in-transit', await api(`/supply-chain/deliveries/${ids.delivery}/status`, { method: 'PATCH', body: { status: 'in_transit' } }), 200);
        expect('L12 delivery delivered', await api(`/supply-chain/deliveries/${ids.delivery}/status`, { method: 'PATCH', body: { status: 'delivered' } }), 200);
        const proof = await api(`/supply-chain/deliveries/${ids.delivery}/proofs`, { method: 'POST', multipart: true, fields: { proof_type: 'signed_dr', notes: 'LIVE-B12 disposable proof' }, filename: 'live-b12-proof.pdf' });
        if (proof.status === 201) {
          expect('L12 delivery proof upload', proof, 201);
          ids.proof = idOf(proof.body);
        } else blocked('L12 delivery proof actor', `Delivery creator ${deliveryActor[0]} cannot write proof; HTTP ${proof.status}; body=${brief(proof.body)}`);
        await loginCustomerPortal();
        await ui('/portal/customer/deliveries', 'l12-customer-deliveries');
        const portalDelivery = await api(`/b2b/customer/deliveries/${ids.delivery}`);
        expect('L12 customer portal reads own delivery', portalDelivery, 200);
        if (ids.proof) {
          const portalConfirm = await api(`/b2b/customer/deliveries/${ids.delivery}/confirm`, { method: 'POST', body: { receiver_name: 'LIVE-B12 Customer Receiver', receiver_position: 'Receiving', delivery_remarks: 'LIVE-B12 portal confirmation' } });
          expect('L12 customer portal confirms delivery', portalConfirm, 200);
          expect('L12 customer portal confirmation idempotent replay', await api(`/b2b/customer/deliveries/${ids.delivery}/confirm`, { method: 'POST', body: { receiver_name: 'LIVE-B12 Customer Receiver' } }), 200);
          const confirmedDelivery = dataOf(portalConfirm.body);
          ids.invoice = confirmedDelivery?.invoice_id ?? confirmedDelivery?.invoice?.id;
          observed('L12 delivery/invoice handoff', `delivery=${ids.delivery}; status=${confirmedDelivery?.status}; invoice=${ids.invoice}; handoff=${confirmedDelivery?.invoice_handoff_status}`);
        } else blocked('L12 customer portal delivery confirmation', 'No proof row was created, so the confirmation guard was not bypassed.');
      }
    }
  } else {
    blocked('L12 outgoing AQL/CoC/delivery/AR', 'No good output and confirmed SO were both available; downstream delivery and invoice were not manufactured.');
  }

  if (ids.invoice) {
    await login('finance', 'finance@ogami.test');
    await ui(`/accounting/invoices/${ids.invoice}`, 'l12-finance-invoice');
    const invoice = await api(`/invoices/${ids.invoice}`);
    expect('L12 Finance reads auto-created draft invoice', invoice, 200);
    const invoiceData = dataOf(invoice.body);
    const item = invoiceData?.items?.[0];
    const deliveredQty = Number(item?.quantity ?? 0);
    const unitPrice = Number(item?.unit_price ?? 0);
    assert('L12 delivered quantity x price reconciles invoice', Math.round(deliveredQty * unitPrice * 100) === Math.round(Number(invoiceData?.subtotal ?? invoiceData?.total_amount ?? 0) * 100), `qty=${item?.quantity}; price=${item?.unit_price}; subtotal=${invoiceData?.subtotal}; total=${invoiceData?.total_amount}`);
    const finalized = await api(`/invoices/${ids.invoice}/finalize`, { method: 'PATCH', body: {} });
    expect('L12 Finance finalizes invoice', finalized, 200);
    expect('L12 invoice finalize terminal replay', await api(`/invoices/${ids.invoice}/finalize`, { method: 'PATCH', body: {} }), 422);
    const finalData = dataOf(finalized.body);
    const cashOptions = await api('/invoices/options');
    const optionValue = JSON.stringify(cashOptions.body).match(/"(?:cash_account_id|cash_account|account_id)"\s*:\s*"([^"]+)"/)?.[1];
    if (!optionValue) blocked('L12 AR collection cash account fixture', `Invoice options exposed no cash account hash; options=${brief(cashOptions.body)}`);
    else {
      const total = Number(finalData?.total_amount ?? invoiceData?.total_amount ?? 0);
      const first = Math.max(0.01, Math.floor(total * 50) / 100);
      const key = `live-b12-collection-${stamp}`;
      const collection = await api(`/invoices/${ids.invoice}/collections`, { method: 'POST', headers: { 'Idempotency-Key': key }, body: { cash_account_id: optionValue, collection_date: new Date().toISOString().slice(0, 10), amount: first.toFixed(2), payment_method: 'bank_transfer', reference_number: `B12-${stamp}`, idempotency_key: key } });
      expect('L12 Finance records partial collection', collection, 201);
      const replay = await api(`/invoices/${ids.invoice}/collections`, { method: 'POST', headers: { 'Idempotency-Key': key }, body: { cash_account_id: optionValue, collection_date: new Date().toISOString().slice(0, 10), amount: first.toFixed(2), payment_method: 'bank_transfer', reference_number: `B12-${stamp}`, idempotency_key: key } });
      expect('L12 collection exact replay idempotent', replay, 201);
      const afterPartial = await api(`/invoices/${ids.invoice}`);
      const remaining = Number(dataOf(afterPartial.body)?.balance ?? 0);
      if (remaining > 0) {
        const key2 = `live-b12-collection-final-${stamp}`;
        expect('L12 Finance records final collection', await api(`/invoices/${ids.invoice}/collections`, { method: 'POST', headers: { 'Idempotency-Key': key2 }, body: { cash_account_id: optionValue, collection_date: new Date().toISOString().slice(0, 10), amount: remaining.toFixed(2), payment_method: 'bank_transfer', reference_number: `B12F-${stamp}`, idempotency_key: key2 } }), 201);
      }
      const paid = await api(`/invoices/${ids.invoice}`);
      observed('L12 AR final reconciliation', `invoice=${ids.invoice}; total=${dataOf(paid.body)?.total_amount}; paid=${dataOf(paid.body)?.amount_paid}; balance=${dataOf(paid.body)?.balance}; status=${dataOf(paid.body)?.status}; journal=${dataOf(paid.body)?.journal_entry_id}`);
      assert('L12 invoice fully collected', Number(dataOf(paid.body)?.balance ?? 1) === 0, brief(paid.body));
      const journal = await api(`/journal-entries?per_page=100`);
      expect('L12 Finance GL journal read', journal, 200);
    }
  } else blocked('L12 invoice/finalize/collection/GL', 'Delivery confirmation did not produce an invoice hash.');

  // L13: complaint-origin NCRs provide legitimate independent source records
  // for each disposition. The in-process failed inspection NCR above is also
  // retained and is used for the corrective/rework path where possible.
  await login('crm', 'crm@ogami.test');
  await ui('/crm/complaints', 'l13-crm-complaints');
  const dispositionTargets = ['scrap', 'rework', 'use_as_is', 'return_to_supplier'];
  const complaintNcrs = [];
  for (const disposition of dispositionTargets) {
    const complaint = await api('/crm/complaints', { method: 'POST', body: { customer_id: ids.customer, product_id: ids.product, sales_order_id: ids.so ?? null, received_date: new Date().toISOString().slice(0, 10), severity: 'medium', description: `LIVE-B12 complaint source for ${disposition} disposition ${stamp}`, affected_quantity: 1 } });
    expect(`L13 creates complaint source for ${disposition}`, complaint, 201);
    const complaintId = idOf(complaint.body);
    retain('complaint', complaintId, `${disposition} NCR source; close lifecycle if possible`);
    const complaintData = dataOf(complaint.body);
    const ncrId = complaintData?.ncr_id ?? complaintData?.ncr?.id;
    if (ncrId) complaintNcrs.push({ complaintId, ncrId, disposition });
    else blocked(`L13 ${disposition} complaint NCR handoff`, `Complaint ${complaintId} did not return an NCR hash; handoff=${complaintData?.ncr_handoff_status}`);
  }
  await login('qc', 'qc@ogami.test');
  await ui('/quality/ncrs', 'l13-qc-ncrs');
  for (const target of complaintNcrs) {
    retain('ncr', target.ncrId, `complaint-origin ${target.disposition}`);
    const action1 = await api(`/quality/ncrs/${target.ncrId}/actions`, { method: 'POST', body: { action_type: 'corrective', description: `LIVE-B12 corrective action for ${target.disposition}`, due_date: new Date(Date.now() + 86400000).toISOString().slice(0, 10) } });
    expect(`L13 ${target.disposition} corrective action`, action1, 201);
    const action2 = await api(`/quality/ncrs/${target.ncrId}/actions`, { method: 'POST', body: { action_type: 'preventive', description: `LIVE-B12 preventive action for ${target.disposition}`, due_date: new Date(Date.now() + 86400000).toISOString().slice(0, 10) } });
    expect(`L13 ${target.disposition} preventive action`, action2, 201);
    expect(`L13 ${target.disposition} disposition`, await api(`/quality/ncrs/${target.ncrId}/disposition`, { method: 'PATCH', body: { disposition: target.disposition, root_cause: 'LIVE-B12 source investigation', corrective_action: `LIVE-B12 controlled ${target.disposition} response` } }), 200);
    const closed = await api(`/quality/ncrs/${target.ncrId}/close`, { method: 'POST', body: {} });
    expect(`L13 ${target.disposition} NCR close`, closed, 200);
    const closedData = dataOf(closed.body);
    observed(`L13 ${target.disposition} downstream effect`, `ncr=${target.ncrId}; status=${closedData?.status}; replacement=${closedData?.replacement_work_order_id}; rework=${closedData?.rework_work_order_id}; return_rma=${closedData?.return_request_id}`);
    const actionIds = [idOf(action1.body), idOf(action2.body)].filter(Boolean);
    for (const actionId of actionIds) expect(`L13 ${target.disposition} CAPA effectiveness`, await api(`/quality/ncrs/${target.ncrId}/actions/${actionId}/verify`, { method: 'PATCH', body: { effectiveness_status: 'effective', notes: 'LIVE-B12 effectiveness verified from controlled evidence' } }), 200);
    expect(`L13 ${target.disposition} NCR terminal replay`, await api(`/quality/ncrs/${target.ncrId}/close`, { method: 'POST', body: {} }), [200, 422]);
    if (target.complaintId) {
      await login('crm', 'crm@ogami.test');
      expect(`L13 ${target.disposition} complaint resolve`, await api(`/crm/complaints/${target.complaintId}/resolve`, { method: 'POST', body: {} }), 200);
      expect(`L13 ${target.disposition} complaint close`, await api(`/crm/complaints/${target.complaintId}/close`, { method: 'POST', body: {} }), 200);
      await login('qc', 'qc@ogami.test');
    }
  }
  await login('qc', 'qc@ogami.test');
  const pareto = await api('/quality/analytics/defect-pareto?from=2026-01-01&to=2026-12-31');
  expect('L13 Quality Pareto evidence', pareto, 200);
  const notifications = await api('/notifications?per_page=100');
  expect('L13 QC notification feed', notifications, 200);
  observed('L13 notification/action-link evidence', `notification_rows=${rowsOf(notifications.body).length}; auto-NCR links are expected in dispatch payloads when recipients are configured`);

  // L14: maintenance interruption uses a disposable machine if available. The
  // condition-reading/threshold routes are intentionally scope-cut, so absence
  // is reported rather than simulated.
  await login('production', 'production@ogami.test');
  const machineOptions = await api('/mrp/machines?per_page=100');
  expect('L14 machine history source list', machineOptions, 200);
  const activeMachine = rowsOf(machineOptions.body).find((row) => row.status !== 'archived' && row.deleted_at == null);
  if (!activeMachine?.id) {
    blocked('L14 machine breakdown source', 'No active machine fixture was available; no machine or condition record was manufactured.');
  } else {
    ids.maintenanceMachine = activeMachine.id;
    await login('maintenance', 'maintenance@ogami.test');
    await ui('/maintenance/work-orders', 'l14-maintenance-work-orders');
    const options = await api('/maintenance/work-orders/options');
    const assignees = await api('/maintenance/work-orders/assignees');
    if (assignees.status !== 200) {
      blocked('L14 maintenance assignment and corrective MWO', `maintenance.wo.assign unavailable to maintenance actor; HTTP ${assignees.status}; no admin bypass.`);
    } else {
      const types = dataOf(options.body)?.types ?? [];
      const priorities = dataOf(options.body)?.priorities ?? [];
      const assignee = rowsOf(assignees.body)[0]?.id;
      const mwo = await api('/maintenance/work-orders', { method: 'POST', body: { maintainable_type: 'machine', maintainable_id: ids.maintenanceMachine, type: types.find((x) => x.value === 'corrective')?.value ?? 'corrective', priority: priorities.find((x) => x.value === 'high')?.value ?? 'high', description: `LIVE-B12 corrective breakdown MWO ${stamp}` } });
      expect('L14 creates corrective MWO', mwo, 201);
      ids.maintenanceWo = idOf(mwo.body);
      retain('maintenance-work-order', ids.maintenanceWo, 'disposable corrective interruption evidence');
      if (ids.maintenanceWo) {
        expect('L14 assigns corrective MWO', await api(`/maintenance/work-orders/${ids.maintenanceWo}/assign`, { method: 'PATCH', body: { employee_id: assignee } }), 200);
        expect('L14 starts corrective MWO', await api(`/maintenance/work-orders/${ids.maintenanceWo}/start`, { method: 'PATCH' }), 200);
        expect('L14 logs corrective MWO', await api(`/maintenance/work-orders/${ids.maintenanceWo}/logs`, { method: 'POST', body: { description: 'LIVE-B12 breakdown diagnosis and repair' } }), 201);
        expect('L14 completes corrective MWO', await api(`/maintenance/work-orders/${ids.maintenanceWo}/complete`, { method: 'PATCH', body: { remarks: 'LIVE-B12 completed corrective repair', downtime_minutes: 22 } }), 200);
        expect('L14 corrective MWO terminal replay', await api(`/maintenance/work-orders/${ids.maintenanceWo}/complete`, { method: 'PATCH', body: { downtime_minutes: 22 } }), 422);
      }
    }
    const health = await api('/maintenance/condition-readings/health-snapshot');
    if (health.status === 404) blocked('L14 condition-reading threshold source', 'Condition-reading routes are scope-cut and absent; no hidden condition-reading assumption was made.');
    else expect('L14 condition-reading route shape', health, 200);
    const analytics = await api('/maintenance/downtime-analytics/summary');
    expect('L14 maintenance downtime analytics', analytics, 200);
  }

  // Direct wrong-role and terminal replay assertions for the principal chain
  // mutations, using the nearest non-owner and disposable terminal records.
  await login('employee', 'employee@ogami.test');
  expect('L14 employee cannot mutate production WO', await api(`/production/work-orders/${ids.wo ?? 'invalid'}/start`, { method: 'POST' }), 403);
  expect('L14 employee cannot mutate NCR', await api(`/quality/ncrs/${ids.inProcessNcr ?? 'invalid'}/close`, { method: 'POST', body: {} }), 403);
  await logout();
} catch (error) {
  record('FAIL', 'L10-L14 live worker harness', error instanceof Error ? error.stack ?? error.message : String(error));
  await shot('failure').catch(() => {});
} finally {
  await logout().catch(() => {});
  await browser.close();
  await fs.writeFile(`${artifactDir}/l10-l14-results.json`, JSON.stringify({ generated_at: new Date().toISOString(), ids, residuals, results, evidence }, null, 2));
}

const counts = results.reduce((out, row) => ({ ...out, [row.status]: (out[row.status] ?? 0) + 1 }), {});
console.log(`COUNTS ${JSON.stringify(counts)}`);
console.log(`IDS ${JSON.stringify(ids)}`);
