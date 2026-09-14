import { firefox } from '../../spa/node_modules/playwright/index.mjs';

const base = 'http://localhost';
const artifact = '/home/kwat0g/Desktop/kwatog/audit/live-2026-09-14/artifacts';
const browser = await firefox.launch({ headless: true });
const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
const page = await context.newPage();
const results = [];
const ids = {};
let actor = 'signed-out';
let lastLogin = 0;
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const data = (body) => body?.data ?? body;
const list = (body) => Array.isArray(data(body)) ? data(body) : [];
const idOf = (body) => data(body)?.id ?? data(body)?.hash_id ?? null;
const short = (value) => JSON.stringify(value ?? '').slice(0, 1200);

function record(status, name, detail = '') { results.push({ status, actor, name, detail }); console.log(`${status} ${name}${detail ? ` :: ${detail}` : ''}`); }
function expect(name, response, statuses, detail = '') { const expected = Array.isArray(statuses) ? statuses : [statuses]; const ok = expected.includes(response.status); record(ok ? 'PASS' : 'FAIL', name, `HTTP ${response.status}; expected ${expected.join('/')} ${detail}; body=${short(response.body)}`); return ok; }
function blocked(name, detail) { record('BLOCKED', name, detail); }
function observed(name, detail) { record('OBSERVED', name, detail); }

async function api(path, options = {}) {
  return page.evaluate(async ({ path, options }) => {
    const token = document.cookie.split('; ').find((cookie) => cookie.startsWith('XSRF-TOKEN='))?.slice(10);
    const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...(token ? { 'X-XSRF-TOKEN': decodeURIComponent(token) } : {}), ...(options.body === undefined ? {} : { 'Content-Type': 'application/json' }), ...(options.headers ?? {}) };
    const response = await fetch(`/api/v1${path}`, { method: options.method ?? 'GET', credentials: 'include', headers, body: options.body === undefined ? undefined : JSON.stringify(options.body) });
    const text = await response.text(); let body; try { body = JSON.parse(text); } catch { body = text; }
    return { status: response.status, body, contentType: response.headers.get('content-type'), disposition: response.headers.get('content-disposition') };
  }, { path, options });
}
async function logout() { if (actor !== 'signed-out') await api('/auth/logout', { method: 'POST' }).catch(() => {}); await context.clearCookies(); actor = 'signed-out'; }
async function login(alias, email) {
  await logout(); const wait = Math.max(0, 13000 - (Date.now() - lastLogin)); if (wait) await sleep(wait);
  await page.goto(`${base}/login`, { waitUntil: 'domcontentloaded' });
  await page.locator('input[type="email"], input[name="email"]').first().fill(email); await page.locator('input[type="password"]').first().fill('password');
  await page.getByRole('button', { name: /sign in|log in/i }).click(); await page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 25000 });
  actor = alias; lastLogin = Date.now(); expect(`${alias} authenticated`, await api('/auth/user'), 200);
}
async function ui(path, name) {
  const response = await page.goto(`${base}${path}`, { waitUntil: 'domcontentloaded' }); await page.locator('body').waitFor({ state: 'visible', timeout: 20000 });
  await page.waitForFunction(() => document.body.innerText.trim().length > 30, null, { timeout: 25000 }); const text = await page.locator('body').innerText();
  await page.screenshot({ path: `${artifact}/s41-s45-${name}.png`, fullPage: true }).catch(() => {});
  record(/application error|internal server error|something went wrong/i.test(text) ? 'FAIL' : 'PASS', `${name} UI`, `document=${response?.status() ?? 'none'} uiUrl=${page.url()}`);
}
async function read(path, name, statuses = 200) { const response = await api(path); expect(name, response, statuses); return response; }

try {
  // PPAP: purchasing only resolves existing vendor/item master hashes; QC owns all PPAP mutations.
  await login('purchasing', 'purchasing@ogami.test');
  const vendors = await read('/vendors?per_page=100', 'S41 seeded vendor lookup');
  const items = await read('/inventory/items?per_page=100', 'S41 seeded item lookup');
  const vendor = list(vendors.body)[0]; const item = list(items.body).find((row) => row.id);
  await login('qc', 'qc@ogami.test'); await ui('/quality/inspections', 'qc-ppap-review');
  if (!vendor?.id || !item?.id) {
    blocked('S41 PPAP disposable lifecycle continuation', 'Seeded vendor/item master lookups did not return hash IDs without manufacturing a fixture.');
  } else {
    const create = await api('/quality/ppap', { method: 'POST', body: { vendor_id: vendor.id, item_id: item.id, ppap_level: '3', submission_date: '2026-09-14', notes: 'Disposable S41 PPAP approval path' } });
    expect('S41 disposable PPAP create', create, 201); ids.ppap = idOf(create.body);
    if (ids.ppap) {
      const detail = await read(`/quality/ppap/${ids.ppap}`, 'S41 PPAP detail continuation'); const element = data(detail.body)?.elements?.[0];
      await expect('S41 PPAP partial edit', await api(`/quality/ppap/${ids.ppap}`, { method: 'PUT', body: { notes: 'S41 prefilled partial edit' } }), 200);
      if (element?.id) { ids.ppapElement = element.id; await expect('S41 PPAP element accepted update', await api(`/quality/ppap/${ids.ppap}/elements/${element.id}`, { method: 'PATCH', body: { status: 'accepted', notes: 'S41 PSW accepted' } }), 200); }
      else blocked('S41 PPAP element update', 'Auto-attached PSW element was absent from the disposable resource.');
      await expect('S41 PPAP submit', await api(`/quality/ppap/${ids.ppap}/submit`, { method: 'PATCH' }), 200);
      await expect('S41 PPAP submit replay terminal guard', await api(`/quality/ppap/${ids.ppap}/submit`, { method: 'PATCH' }), 422);
      await expect('S41 PPAP review', await api(`/quality/ppap/${ids.ppap}/review`, { method: 'PATCH' }), 200);
      await expect('S41 PPAP approve', await api(`/quality/ppap/${ids.ppap}/approve`, { method: 'PATCH' }), 200);
      await expect('S41 approved PPAP edit terminal guard', await api(`/quality/ppap/${ids.ppap}`, { method: 'PUT', body: { notes: 'illegal approved edit' } }), 422);
      await expect('S41 approved PPAP evidence freeze', await api(`/quality/ppap/${ids.ppap}/elements/${ids.ppapElement}`, { method: 'PATCH', body: { status: 'rejected' } }), 422);
      const duplicate = await api('/quality/ppap', { method: 'POST', body: { vendor_id: vendor.id, item_id: item.id, ppap_level: '3', submission_date: '2026-09-14', notes: 'S41 exact duplicate probe' } });
      if (duplicate.status === 201) { ids.ppapDuplicate = idOf(duplicate.body); observed('S41 exact duplicate PPAP create', `HTTP 201 created second disposable submission ${ids.ppapDuplicate}; no unique duplicate contract is documented.`); }
      else expect('S41 exact duplicate PPAP create validation', duplicate, 422);
      if ((data(detail.body)?.elements?.length ?? 0) < 18) blocked('S41 18-element PPAP update set', 'Creation auto-attaches one PSW and no add-element route exists; seeded submissions were absent/preserved.');
    }
  }

  // Maintenance: the seeded maintenance role can create/complete, but not assign or manage schedules.
  await login('maintenance', 'maintenance@ogami.test'); await ui('/maintenance/work-orders', 'maintenance-execution-continuation');
  const mwoList = await read('/maintenance/work-orders?per_page=100', 'S42 maintenance WO list continuation'); const existing = list(mwoList.body)[0];
  const targetType = existing?.maintainable_type; const targetId = existing?.maintainable?.id;
  const created = targetType && targetId ? await api('/maintenance/work-orders', { method: 'POST', body: { maintainable_type: targetType, maintainable_id: targetId, type: 'corrective', priority: 'low', description: 'Disposable S42 complete maintenance WO continuation' } }) : null;
  if (!created) blocked('S42 disposable maintenance WO execution continuation', 'No maintainable hash was available.');
  else {
    expect('S42 disposable maintenance WO create continuation', created, 201); ids.mwo = idOf(created.body);
    if (ids.mwo) {
      const assignees = await api('/maintenance/work-orders/assignees'); expect('S42 assignment route permission boundary', assignees, 403);
      blocked('S42 maintenance assignment', 'Seeded maintenance_tech has maintenance.wo.create/complete but not maintenance.wo.assign; no missing permission was manufactured.');
      await expect('S42 maintenance start without assignment', await api(`/maintenance/work-orders/${ids.mwo}/start`, { method: 'PATCH' }), 200);
      await expect('S42 maintenance lifecycle log continuation', await api(`/maintenance/work-orders/${ids.mwo}/logs`, { method: 'POST', body: { description: 'S42 diagnostic and repair log' } }), 201);
      await expect('S42 invalid spare part references continuation', await api(`/maintenance/work-orders/${ids.mwo}/spare-parts`, { method: 'POST', body: { item_id: 'invalid', location_id: 'invalid', quantity: '1.000' } }), 422);
      await expect('S42 maintenance complete continuation', await api(`/maintenance/work-orders/${ids.mwo}/complete`, { method: 'PATCH', body: { remarks: 'S42 completed disposable repair', downtime_minutes: 17 } }), 200);
      await expect('S42 completed maintenance terminal replay continuation', await api(`/maintenance/work-orders/${ids.mwo}/complete`, { method: 'PATCH', body: { downtime_minutes: 17 } }), 422);
    }
    const cancel = await api('/maintenance/work-orders', { method: 'POST', body: { maintainable_type: targetType, maintainable_id: targetId, type: 'corrective', priority: 'low', description: 'Disposable S42 cancel maintenance WO continuation' } });
    expect('S42 disposable cancel WO create continuation', cancel, 201); ids.mwoCancel = idOf(cancel.body);
    if (ids.mwoCancel) { await expect('S42 maintenance cancel continuation', await api(`/maintenance/work-orders/${ids.mwoCancel}/cancel`, { method: 'PATCH', body: { reason: 'S42 cleanup cancellation' } }), 200); await expect('S42 cancelled maintenance terminal replay continuation', await api(`/maintenance/work-orders/${ids.mwoCancel}/start`, { method: 'PATCH' }), 422); }
  }
  const scheduleProbe = await api('/maintenance/schedules', { method: 'POST', body: { maintainable_type: targetType ?? 'machine', maintainable_id: targetId ?? 'invalid', description: 'S42 schedule permission probe', interval_type: 'days', interval_value: 30 } });
  expect('S42 schedule-management permission boundary', scheduleProbe, 403); blocked('S42 schedule CRUD', 'maintenance_tech cannot create/update/archive schedules; no seeded schedule was changed and no stronger role was invented.');

  // RMA fallback: a finance-only customer credit has no source reservation or stock movement,
  // but exercises the real CRM -> approval -> warehouse -> QC -> credit-note terminal flow.
  await login('crm', 'crm@ogami.test'); await ui('/return-management', 'crm-rma-finance-only');
  const customers = await read('/crm/customers?per_page=100', 'S45 customer lookup continuation'); const products = await read('/crm/products?per_page=100', 'S45 product lookup continuation');
  const customer = list(customers.body)[0]; const product = list(products.body)[0];
  if (!customer?.id || !product?.id) blocked('S45 finance-only RMA chain', 'Customer/product hashes were not available.');
  else {
    const createRma = async (note) => api('/return-management/return-requests', { method: 'POST', body: { type: 'customer_return', customer_id: customer.id, finance_only: true, finance_only_reason: note, reason_code: 'defective', reason_description: note, items: [{ product_id: product.id, quantity: '1.000', unit_price: '31.00', condition: 'defective', reason: note }] } });
    const rma = await createRma('Disposable S45 finance-only full chain'); expect('S45 finance-only RMA create continuation', rma, 201); ids.rma = idOf(rma.body);
    if (ids.rma) {
      const detail = await read(`/return-management/return-requests/${ids.rma}`, 'S45 finance-only RMA detail'); const line = data(detail.body)?.items?.[0]?.id;
      await expect('S45 finance-only RMA partial edit continuation', await api(`/return-management/return-requests/${ids.rma}`, { method: 'PATCH', body: { customer_notes: 'S45 partial edit continuation' } }), 200);
      await expect('S45 finance-only RMA submit continuation', await api(`/return-management/return-requests/${ids.rma}/submit`, { method: 'POST' }), 200);
      await expect('S45 finance-only RMA submit replay continuation', await api(`/return-management/return-requests/${ids.rma}/submit`, { method: 'POST' }), 422);
      await login('depthead', 'depthead@ogami.test'); await ui(`/return-management/${ids.rma}`, 'depthead-rma-finance-only');
      await expect('S45 department RMA approval continuation', await api(`/return-management/return-requests/${ids.rma}/approve`, { method: 'POST', body: { remarks: 'S45 department approval' } }), 200);
      await login('production', 'production@ogami.test'); await expect('S45 production RMA approval continuation', await api(`/return-management/return-requests/${ids.rma}/approve`, { method: 'POST', body: { remarks: 'S45 production approval' } }), 200);
      await login('warehouse', 'warehouse@ogami.test'); await ui(`/return-management/${ids.rma}`, 'warehouse-rma-finance-only');
      await expect('S45 finance-only RMA receive continuation', await api(`/return-management/return-requests/${ids.rma}/receive`, { method: 'POST', body: { received_quantities: { [line]: '1.000' } } }), 200);
      await login('qc', 'qc@ogami.test');
      const staged = await api(`/return-management/return-requests/${ids.rma}/inspect`, { method: 'POST', body: { internal_notes: 'S45 QC finance-only inspection staging' } }); expect('S45 QC finance-only inspection staging', staged, 200);
      const stagedDetail = await read(`/return-management/return-requests/${ids.rma}`, 'S45 finance-only staged RMA'); ids.rmaInspection = data(stagedDetail.body)?.inspection_id;
      if (ids.rmaInspection) {
        const inspection = await read(`/quality/inspections/${ids.rmaInspection}`, 'S45 finance-only return inspection'); const measurements = data(inspection.body)?.measurements ?? [];
        if (measurements.length) {
          const rows = measurements.map((m) => ({ id: m.id, measured_value: String(m.nominal_value ?? '100.0000'), is_pass: true, notes: 'S45 returned item actual' }));
          await expect('S45 QC finance-only measurements', await api(`/quality/inspections/${ids.rmaInspection}/measurements`, { method: 'PATCH', body: { measurements: rows } }), 200);
          await expect('S45 QC finance-only inspection complete', await api(`/quality/inspections/${ids.rmaInspection}/complete`, { method: 'POST' }), 200);
        } else blocked('S45 QC finance-only measurement completion', 'Auto-created inspection had no measurement rows.');
      } else blocked('S45 finance-only inspection handoff', `No inspection hash returned; handoff=${data(staged.body)?.inspection_handoff_status ?? 'missing'}.`);
      await login('warehouse', 'warehouse@ogami.test'); const beforeDispose = await read(`/return-management/return-requests/${ids.rma}`, 'S45 finance-only RMA before dispose');
      const disposed = await api(`/return-management/return-requests/${ids.rma}/dispose`, { method: 'POST', body: { dispositions: [{ item_id: data(beforeDispose.body)?.items?.[0]?.id ?? line, disposition: 'no_return', notes: 'S45 finance-only no-goods credit' }] } });
      expect('S45 finance-only RMA dispose no-return', disposed, 200);
      if (disposed.status === 200) {
        observed('S45 finance-only RMA credit effect', `status=${data(disposed.body)?.status}; disposition=${data(disposed.body)?.disposition_status}; credit_note=${data(disposed.body)?.credit_note?.id ?? data(disposed.body)?.credit_note_id ?? null}; inventory/NCR intentionally none by finance-only policy.`);
        await expect('S45 finance-only RMA complete', await api(`/return-management/return-requests/${ids.rma}/complete`, { method: 'POST', body: {} }), 200);
        await expect('S45 finance-only RMA complete replay', await api(`/return-management/return-requests/${ids.rma}/complete`, { method: 'POST', body: {} }), 422);
      }
    }
    const rejected = await createRma('Disposable S45 reject path'); expect('S45 disposable rejected RMA create', rejected, 201); ids.rmaReject = idOf(rejected.body);
    if (ids.rmaReject) {
      await expect('S45 rejected RMA submit', await api(`/return-management/return-requests/${ids.rmaReject}/submit`, { method: 'POST' }), 200); await login('depthead', 'depthead@ogami.test');
      await expect('S45 department RMA reject', await api(`/return-management/return-requests/${ids.rmaReject}/reject`, { method: 'POST', body: { reason: 'S45 disposable rejection with sufficient remarks' } }), 200);
      await expect('S45 rejected RMA terminal replay', await api(`/return-management/return-requests/${ids.rmaReject}/submit`, { method: 'POST' }), 422);
    }
    const cancelled = await createRma('Disposable S45 cancel path'); expect('S45 disposable cancelled RMA create', cancelled, 201); ids.rmaCancel = idOf(cancelled.body);
    if (ids.rmaCancel) { await expect('S45 RMA cancel continuation', await api(`/return-management/return-requests/${ids.rmaCancel}/cancel`, { method: 'POST', body: { reason: 'S45 disposable cancel cleanup' } }), 200); await expect('S45 cancelled RMA terminal replay continuation', await api(`/return-management/return-requests/${ids.rmaCancel}/submit`, { method: 'POST' }), 422); }
    blocked('S45 source-backed inventory/replacement/NCR effects', 'No customer invoice/delivery line with remaining stockable quantity existed. The finance-only policy deliberately produces a credit-only/no-return path and cannot prove inventory, replacement PO, or auto-NCR effects.');
  }
} catch (error) { record('FAIL', 'S41-S45 continuation harness', error instanceof Error ? error.stack ?? error.message : String(error)); }
finally { await logout().catch(() => {}); await browser.close(); }

const counts = results.reduce((out, row) => ({ ...out, [row.status]: (out[row.status] ?? 0) + 1 }), {});
console.log(JSON.stringify({ ids, counts, results }, null, 2));
