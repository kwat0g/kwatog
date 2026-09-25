// Order-to-cash browser acceptance run: the happy path and the failure paths,
// driven through the real SPA and API with one browser context per role.
//
//   node scripts/o2c-headless.cjs            # from the repo root, stack running
//
// Self-contained: creates ogami_test_o2c_browser_<run>, migrates it, seeds master
// data (api/tests/Browser/o2c_fixture.php), serves a temporary API on it
// (QUEUE_CONNECTION=sync so every listener runs inline, as a worker would) and a
// temporary Vite proxy, then tears everything down. The shared dev database is
// never touched. O2C_KEEP=1 keeps the database and servers for inspection.
const path = require('node:path');
const fs = require('node:fs');
const { execFileSync } = require('node:child_process');
const { pathToFileURL } = require('node:url');

const ROOT = path.resolve(__dirname, '..');
const SPA = path.join(ROOT, 'spa');
const { chromium } = require(path.join(SPA, 'node_modules/playwright'));

const RUN = (process.env.O2C_RUN_ID || `O2C${Date.now().toString(36)}`).toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 16);
const DB = `ogami_test_o2c_browser_${RUN.toLowerCase()}`;
const API_PORT = Number(process.env.O2C_API_PORT || 8230);
const SPA_PORT = Number(process.env.O2C_SPA_PORT || 5230);
const BASE = `http://127.0.0.1:${SPA_PORT}`;
const OUT = process.env.O2C_OUTPUT || path.join('/tmp', `o2c-headless-${RUN}`);
const KEEP = process.env.O2C_KEEP === '1';
const FIXTURE_IN_CONTAINER = `/tmp/o2c-fixture-${RUN}.json`;
const ENV = {
  DB_DATABASE: DB, CACHE_STORE: 'array', QUEUE_CONNECTION: 'sync', MAIL_MAILER: 'array',
  BROADCAST_CONNECTION: 'log', SESSION_DRIVER: 'database', APP_URL: BASE,
  SANCTUM_STATEFUL_DOMAINS: `127.0.0.1:${SPA_PORT},localhost:${SPA_PORT}`,
  O2C_BROWSER_DB: DB, O2C_RUN_ID: RUN, O2C_FIXTURE_PATH: FIXTURE_IN_CONTAINER,
};

const report = { run_id: RUN, database: DB, base_url: BASE, checks: [], failures: [], http_errors: [], page_errors: [] };
let fixture;

function check(name, ok, evidence = '') {
  const text = typeof evidence === 'string' ? evidence : JSON.stringify(evidence);
  (ok ? report.checks : report.failures).push({ name, evidence: text });
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${text ? ` — ${text.slice(0, 300)}` : ''}`);
  return ok;
}

/* ─── Environment ─────────────────────────────────────────────────── */

function compose(args) {
  return execFileSync('docker', ['compose', ...args], { cwd: ROOT, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'], maxBuffer: 64 * 1024 * 1024 });
}
const envFlags = () => Object.entries(ENV).flatMap(([k, v]) => ['-e', `${k}=${v}`]);
const artisan = (...args) => compose(['exec', '-T', ...envFlags(), 'api', 'php', '-d', 'memory_limit=1G', 'artisan', ...args]);
const psql = (sql, db = 'postgres') => compose(['exec', '-T', 'db', 'psql', '-U', 'ogami', '-d', db, '-At', '-F', '|', '-c', sql]).trim();
const sql = (query) => psql(query, DB);
const apiIp = () => execFileSync('docker', ['inspect', '-f', '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}', 'ogami-api'], { encoding: 'utf8' }).trim();

async function setUp() {
  fs.mkdirSync(OUT, { recursive: true });
  psql(`CREATE DATABASE ${DB} OWNER ogami;`);
  artisan('migrate', '--force');
  artisan('tinker', '--execute', "require 'tests/Browser/o2c_fixture.php';");
  compose(['cp', `api:${FIXTURE_IN_CONTAINER}`, path.join(OUT, 'fixture.json')]);
  fixture = JSON.parse(fs.readFileSync(path.join(OUT, 'fixture.json'), 'utf8'));
  compose(['exec', '-d', ...envFlags(), 'api', 'php', 'artisan', 'serve', '--no-reload', '--host=0.0.0.0', `--port=${API_PORT}`]);
  const target = `http://${apiIp()}:${API_PORT}`;
  for (let i = 0; i < 60; i++) {
    try { if ((await fetch(`${target}/sanctum/csrf-cookie`)).status < 500) break; } catch { /* booting */ }
    await new Promise((r) => setTimeout(r, 1000));
  }
  const vite = await import(pathToFileURL(path.join(SPA, 'node_modules/vite/dist/node/index.js')).href);
  const server = await vite.createServer({
    configFile: path.join(SPA, 'vite.config.ts'), root: SPA, logLevel: 'error',
    server: {
      host: '127.0.0.1', port: SPA_PORT, strictPort: true,
      hmr: { host: '127.0.0.1', port: SPA_PORT, clientPort: SPA_PORT },
      proxy: { '/api': { target, changeOrigin: false }, '/sanctum': { target, changeOrigin: false } },
    },
  });
  await server.listen();
  return server;
}

function tearDown(server) {
  if (KEEP) { console.log(`Kept ${DB}, API :${API_PORT} and SPA ${BASE}.`); return; }
  try { server?.close(); } catch { /* already closed */ }
  try { compose(['exec', '-T', 'api', 'pkill', '-f', `port=${API_PORT}`]); } catch { /* not running */ }
  try { compose(['exec', '-T', 'api', 'pkill', '-f', `0.0.0.0:${API_PORT}`]); } catch { /* not running */ }
  try { compose(['exec', '-T', 'api', 'rm', '-f', FIXTURE_IN_CONTAINER]); } catch { /* gone */ }
  try {
    psql(`SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname='${DB}' AND pid <> pg_backend_pid();`);
    psql(`DROP DATABASE IF EXISTS ${DB};`);
  } catch (e) { console.log(`Could not drop ${DB}: ${e.message}`); }
}

/* ─── Browser helpers ─────────────────────────────────────────────── */

let browser;
async function login(email) {
  browser ??= await chromium.launch({ headless: true });
  const context = await browser.newContext({ baseURL: BASE, viewport: { width: 1440, height: 1000 }, timezoneId: 'Asia/Manila' });
  // Echo would reach the shared dev Reverb; answer the Pusher handshake locally.
  await context.routeWebSocket((url) => new URL(url).pathname.startsWith('/app/'), (socket) => {
    socket.onMessage((message) => {
      let frame; try { frame = JSON.parse(String(message)); } catch { return; }
      if (frame.event === 'pusher:ping') socket.send(JSON.stringify({ event: 'pusher:pong', data: {} }));
      if (frame.event === 'pusher:subscribe') socket.send(JSON.stringify({ event: 'pusher_internal:subscription_succeeded', channel: frame.data?.channel, data: '{}' }));
    });
    socket.send(JSON.stringify({ event: 'pusher:connection_established', data: JSON.stringify({ socket_id: '1.1', activity_timeout: 120 }) }));
  });
  const page = await context.newPage();
  page.on('pageerror', (e) => report.page_errors.push(`${email}: ${e.message}`));
  page.on('response', (r) => {
    if (r.status() >= 500 && r.url().includes('/api/')) report.http_errors.push(`${email} ${r.status()} ${r.request().method()} ${r.url().replace(BASE, '')}`);
  });
  await page.goto('/sign-in');
  await page.getByLabel('Email').fill(email);
  await page.getByLabel('Password', { exact: true }).fill('password');
  await page.getByRole('button', { name: /sign in/i }).click();
  await page.waitForURL((u) => !u.pathname.startsWith('/sign-in'), { timeout: 30_000 });
  return page;
}

async function api(page, method, route, body, headers = {}) {
  return page.evaluate(async ({ method, route, body, headers }) => {
    const xsrf = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
    const r = await fetch(`/api/v1${route}`, {
      method, credentials: 'include',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf, ...headers },
      body: body === undefined ? undefined : JSON.stringify(body),
    });
    const type = r.headers.get('content-type') || '';
    return { status: r.status, body: type.includes('json') ? await r.json().catch(() => null) : { contentType: type } };
  }, { method, route, body, headers });
}
const msg = (r) => `${r.status} ${r.body?.message ?? ''}`.trim();
const today = () => new Date(Date.now() + 8 * 3600e3).toISOString().slice(0, 10);
const inDays = (n) => new Date(Date.now() + 8 * 3600e3 + n * 864e5).toISOString().slice(0, 10);

async function shot(page, route, name) {
  await page.goto(route);
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.waitForTimeout(800);
  const file = path.join(OUT, `${name}.png`);
  await page.screenshot({ path: file, fullPage: true });
  return file;
}

async function uploadProof(driver, deliveryId) {
  const png = (await driver.screenshot({ type: 'png', clip: { x: 0, y: 0, width: 80, height: 60 } })).toString('base64');
  return driver.evaluate(async ({ id, b64 }) => {
    const xsrf = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
    const form = new FormData();
    form.append('photo', new Blob([Uint8Array.from(atob(b64), (c) => c.charCodeAt(0))], { type: 'image/png' }), 'receipt.png');
    const r = await fetch(`/api/v1/driver/deliveries/${id}/receipt`, { method: 'POST', credentials: 'include', headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf }, body: form });
    return r.status;
  }, { id: deliveryId, b64: png });
}

/* ─── Shared chain steps ──────────────────────────────────────────── */

const who = {};
async function logins() {
  for (const [key, email] of Object.entries({
    sales: 'crm@ogami.test', ppc: 'ppc@ogami.test', prod: 'production@ogami.test', qc: 'qc@ogami.test',
    impex: 'impex@ogami.test', warehouse: 'warehouse@ogami.test', driver: 'driver@ogami.test',
    finance: 'finance@ogami.test', cs: 'customerservice@ogami.test', customer: fixture.customer_email,
  })) who[key] = await login(email);
}

async function orderAndPlan(label, quantity) {
  let r = await api(who.sales, 'POST', '/crm/sales-orders', {
    customer_id: fixture.customer, date: today(), notes: `O2C browser ${label}`,
    items: [{ product_id: fixture.product, quantity: String(quantity), delivery_date: inDays(14) }],
  });
  const so = r.body?.data;
  check(`${label}: sales officer creates the order`, r.status === 201, `${msg(r)} ${so?.so_number} total=${so?.total_amount}`);
  r = await api(who.sales, 'POST', `/crm/sales-orders/${so.id}/confirm`);
  check(`${label}: order confirmed; MRP plans it inline`, r.status === 200 && r.body?.chain_result?.planning_status === 'completed', `${msg(r)} planning=${r.body?.chain_result?.planning_status}`);
  return so;
}

async function openWorkOrder(so, label) {
  const woNumber = sql(`select wo_number from work_orders where sales_order_id=(select id from sales_orders where so_number='${so.so_number}') and status in ('planned','confirmed') order by id desc limit 1`);
  const wo = (await api(who.prod, 'GET', `/production/work-orders?search=${woNumber}`)).body?.data?.[0];
  check(`${label}: MRP drafted work order ${woNumber}`, !!wo, `target=${wo?.quantity_target}`);
  if (wo.status === 'planned') {
    const run = await api(who.ppc, 'POST', '/mrp/scheduler/run', { work_order_ids: [wo.id] });
    const ids = (run.body?.data?.scheduled ?? []).map((s) => s.id);
    const confirm = await api(who.prod, 'POST', '/mrp/scheduler/confirm', { schedule_ids: ids });
    check(`${label}: PPC schedules, production manager confirms`, run.status === 200 && confirm.status === 200 && ids.length === 1, `${msg(run)} / ${msg(confirm)}`);
  }
  const start = await api(who.prod, 'POST', `/production/work-orders/${wo.id}/start`, {});
  check(`${label}: work order started`, start.status === 200, msg(start));
  return wo;
}

async function findInspection(page, number) {
  return ((await api(page, 'GET', `/quality/inspections?search=${number}`)).body?.data ?? []).find((i) => i.inspection_number === number);
}

async function measure(number, label, { fail = false } = {}) {
  const found = await findInspection(who.qc, number);
  const detail = (await api(who.qc, 'GET', `/quality/inspections/${found.id}`)).body?.data;
  const rows = (detail?.measurements ?? []).map((m, i) => (m.parameter_type === 'dimensional'
    ? { id: m.id, measured_value: fail && i < 4 ? '10.200' : (i % 2 ? '10.010' : '9.990') }
    : { id: m.id, is_pass: true }));
  const saved = await api(who.qc, 'PATCH', `/quality/inspections/${found.id}/measurements`, { measurements: rows });
  const done = await api(who.qc, 'POST', `/quality/inspections/${found.id}/complete`, {});
  check(`${label}: ${number} measured (sample ${detail?.sample_size} of ${detail?.batch_quantity}) → ${done.body?.data?.status}`, saved.status === 200 && done.status === 200, `${msg(saved)} / ${msg(done)}`);
  return { ...detail, status: done.body?.data?.status, proposed: done.body?.data?.proposed_result };
}

async function produceAndInspect(so, wo, label, outputs, { failOutgoing = false } = {}) {
  const woDb = sql(`select id from work_orders where wo_number='${wo.wo_number}'`);
  const inProcess = sql(`select inspection_number from inspections where entity_type='work_order' and entity_id=${woDb} and stage='in_process'`);
  if (inProcess) {
    const done = await measure(inProcess, `${label} in-process`);
    check(`${label}: in-process QC samples a few pieces, not the whole run`, Number(done.sample_size) <= 5, `sample=${done.sample_size}`);
  }
  for (const [index, [good, reject]] of outputs.entries()) {
    const body = { good_count: good, reject_count: reject, shift: 'A' };
    if (reject) body.defects = [{ defect_type_id: fixture.defect_type, count: reject }];
    const r = await api(who.prod, 'POST', `/production/work-orders/${wo.id}/outputs`, body, { 'X-Idempotency-Key': `${RUN}-${wo.wo_number}-${index}` });
    check(`${label}: output ${good} good / ${reject} reject recorded`, r.status === 201, msg(r));
  }
  const complete = await api(who.prod, 'POST', `/production/work-orders/${wo.id}/complete`, {});
  check(`${label}: work order completed`, complete.status === 200, msg(complete));
  const outgoing = sql(`select i.inspection_number from inspections i join work_order_outputs o on o.id=i.work_order_output_id where o.work_order_id=${woDb} and i.stage='outgoing' order by i.id`).split('\n').filter(Boolean);
  check(`${label}: outgoing QC auto-created per output batch`, outgoing.length === outputs.length, outgoing.join(', '));
  const results = [];
  for (const number of outgoing) results.push(await measure(number, `${label} outgoing`, { fail: failOutgoing }));
  return results;
}

async function review(number, decision, label, remarks) {
  const found = await findInspection(who.prod, number);
  const r = await api(who.prod, 'PATCH', `/quality/inspections/${found.id}/review`, remarks ? { decision, remarks } : { decision });
  check(`${label}: checker records ${number} as ${decision}`, r.status === 200 && r.body?.data?.status === decision, msg(r));
  return found;
}

async function dispatchAndConfirm(so, label) {
  const numbers = sql(`select delivery_number from deliveries where sales_order_id=(select id from sales_orders where so_number='${so.so_number}') and status='scheduled' order by id`).split('\n').filter(Boolean);
  check(`${label}: a delivery is drafted for each passed batch`, numbers.length > 0, numbers.join(', '));
  const vehicle = fixture.vehicle;
  const driverId = ((await api(who.impex, 'GET', '/supply-chain/deliveries/driver-options')).body?.data ?? [])[0]?.id;
  for (const number of numbers) {
    const d = ((await api(who.impex, 'GET', `/supply-chain/deliveries?search=${number}`)).body?.data ?? []).find((x) => x.delivery_number === number);
    let r = await api(who.impex, 'PATCH', `/supply-chain/deliveries/${d.id}/assignment`, { vehicle_id: vehicle, driver_id: driverId, reason: 'O2C browser dispatch' });
    check(`${label}: ImpEx assigns van and driver to ${number}`, r.status === 200, msg(r));
    for (const next of ['loading', 'in_transit', 'delivered']) {
      r = await api(who.impex, 'PATCH', `/supply-chain/deliveries/${d.id}/status`, { status: next });
      if (!check(`${label}: ${number} → ${next}`, r.status === 200, msg(r))) break;
    }
    r = await api(who.customer, 'GET', `/b2b/customer/deliveries/${d.id}`);
    check(`${label}: portal holds Confirm until a proof exists`, r.body?.data?.can_confirm === false, `can_confirm=${r.body?.data?.can_confirm}`);
    check(`${label}: driver uploads proof for ${number}`, (await uploadProof(who.driver, d.id)) === 200);
    r = await api(who.customer, 'POST', `/b2b/customer/deliveries/${d.id}/confirm`, { receiver_name: 'O2C receiver', receiver_position: 'Warehouse', delivery_remarks: 'received' });
    check(`${label}: customer confirms ${number} in the portal`, r.status === 200 && r.body?.data?.status === 'confirmed', msg(r));
  }
  return numbers;
}

async function draftInvoices(so) {
  const all = (await api(who.finance, 'GET', '/invoices?per_page=100')).body?.data ?? [];
  return all.filter((i) => i.sales_order?.so_number === so.so_number || i.sales_order_number === so.so_number);
}
const soStatus = (so) => sql(`select status from sales_orders where so_number='${so.so_number}'`);
async function collect(invoice, amount, key) {
  return api(who.finance, 'POST', `/invoices/${invoice.id}/collections`, {
    cash_account_id: fixture.cash_account, collection_date: today(), amount, payment_method: 'bank_transfer',
    reference_number: key.slice(0, 50), idempotency_key: key,
  });
}

/* ─── Scenarios ───────────────────────────────────────────────────── */

async function happyPath() {
  const label = 'Happy path';
  const so = await orderAndPlan(label, 200);
  const wo = await openWorkOrder(so, label);
  let r = await api(who.prod, 'POST', `/production/work-orders/${wo.id}/complete`, {});
  check(`${label}: a work order with no good output cannot complete`, r.status === 422, msg(r));
  const results = await produceAndInspect(so, wo, label, [[150, 3], [50, 0]]);
  check(`${label}: outgoing QC uses the Z1.4 sample (20 pieces for lots up to 280)`, results.every((x) => Number(x.sample_size) === 20), results.map((x) => x.sample_size).join(','));
  const queue = (await api(who.prod, 'GET', '/dashboards/action-center')).body?.data?.items ?? [];
  check(`${label}: the checker sees both reviews in the Action Center`, results.every((x) => queue.some((i) => i.reference === x.inspection_number)), queue.filter((i) => i.kind === 'inspection_review').map((i) => i.reference).join(','));
  for (const x of results) await review(x.inspection_number, 'passed', label);
  await dispatchAndConfirm(so, label);
  const invoices = await draftInvoices(so);
  check(`${label}: one draft invoice per confirmed delivery`, invoices.length === 2, invoices.map((i) => `${i.total_amount}`).join(' + '));
  for (const inv of invoices) {
    const f = await api(who.finance, 'PATCH', `/invoices/${inv.id}/finalize`, {});
    const c = await collect(inv, f.body?.data?.total_amount, `${RUN}-H-${inv.id}`);
    check(`${label}: finance finalizes and collects ${f.body?.data?.total_amount}`, f.status === 200 && c.status === 201, `${msg(f)} / ${msg(c)}`);
  }
  check(`${label}: the order closes once everything is delivered and paid`, soStatus(so) === 'closed', soStatus(so));
  await shot(who.sales, `/crm/sales-orders/${so.id}`, 'happy-so-closed');
  return so;
}

async function failedBatchIsReplacedOnce() {
  const label = 'Failed batch';
  const so = await orderAndPlan(label, 100);
  const wo = await openWorkOrder(so, label);
  const [result] = await produceAndInspect(so, wo, label, [[100, 0]], { failOutgoing: true });
  check(`${label}: inspector proposes a failed result`, result.status === 'awaiting_review' && result.proposed === 'failed', `${result.status}/${result.proposed}`);
  const found = await findInspection(who.prod, result.inspection_number);
  let r = await api(who.prod, 'PATCH', `/quality/inspections/${found.id}/review`, { decision: 'failed' });
  check(`${label}: failing a result needs the checker's remarks`, r.status === 422, msg(r));
  await review(result.inspection_number, 'failed', label, 'Outer diameter above tolerance on 2 of 20 samples.');
  const ncr = ((await api(who.qc, 'GET', `/quality/ncrs?inspection_id=${found.id}`)).body?.data ?? [])[0];
  check(`${label}: an NCR opened for the failed batch`, !!ncr, `${ncr?.ncr_number} ${ncr?.status}`);
  const soId = sql(`select id from sales_orders where so_number='${so.so_number}'`);
  const replacements = sql(`select wo_number||':'||status||':'||quantity_target from work_orders where sales_order_id=${soId} and id <> (select id from work_orders where wo_number='${wo.wo_number}') and status <> 'cancelled'`);
  check(`${label}: MRP re-planned the order straight away (one replacement WO for 100)`, replacements.split('\n').filter(Boolean).length === 1 && replacements.endsWith(':100'), replacements || 'none');
  check(`${label}: nothing ships from the failed batch`, sql(`select count(*) from deliveries where sales_order_id=${soId}`) === '0');

  for (const [type, text] of [['corrective', 'Re-qualified the mold cavity and adjusted hold pressure.'], ['preventive', 'Added a first-article dimensional check to every mold change.']]) {
    r = await api(who.qc, 'POST', `/quality/ncrs/${ncr.id}/actions`, { action_type: type, description: text });
    check(`${label}: QC records the ${type} action`, r.status === 201 || r.status === 200, msg(r));
  }
  r = await api(who.qc, 'PATCH', `/quality/ncrs/${ncr.id}/disposition`, { disposition: 'scrap', root_cause: 'Worn cavity insert oversized the outer diameter.' });
  check(`${label}: disposition set to scrap`, r.status === 200, msg(r));
  r = await api(who.qc, 'POST', `/quality/ncrs/${ncr.id}/close`, {});
  check(`${label}: NCR closes`, r.status === 200 && r.body?.data?.status === 'closed', msg(r));
  const after = sql(`select count(*) from work_orders where sales_order_id=${soId} and id <> (select id from work_orders where wo_number='${wo.wo_number}') and status <> 'cancelled'`);
  check(`${label}: closing the NCR adds no second replacement WO`, after === '1', `open replacement WOs=${after}`);

  const replacement = (await api(who.prod, 'GET', `/production/work-orders?search=${replacements.split(':')[0]}`)).body?.data?.[0];
  await openWorkOrder(so, `${label} replacement`);
  const [ok] = await produceAndInspect(so, replacement, `${label} replacement`, [[100, 0]]);
  await review(ok.inspection_number, 'passed', `${label} replacement`);
  await dispatchAndConfirm(so, `${label} replacement`);
  return so;
}

async function billingCorrections(so) {
  const label = 'Billing';
  let [invoice] = await draftInvoices(so);
  let r = await api(who.finance, 'PATCH', `/invoices/${invoice.id}/finalize`, {});
  check(`${label}: invoice finalized`, r.status === 200 && soStatus(so) === 'invoiced', `${msg(r)} so=${soStatus(so)}`);
  r = await api(who.finance, 'PATCH', `/invoices/${invoice.id}/cancel`, {});
  check(`${label}: an unpaid invoice can be cancelled`, r.status === 200 && r.body?.data?.status === 'cancelled', msg(r));
  check(`${label}: the order steps back to delivered`, soStatus(so) === 'delivered', soStatus(so));
  const reversal = sql(`select count(*) from journal_entries where reference_type='invoice' and reference_id=(select id from invoices where invoice_number='${r.body?.data?.invoice_number}')`);
  check(`${label}: cancelling posts a reversing entry`, Number(reversal) >= 2, `invoice JEs=${reversal}`);
  const delivery = ((await api(who.finance, 'GET', `/supply-chain/deliveries?search=${so.so_number}`)).body?.data ?? [])[0]
    ?? ((await api(who.impex, 'GET', `/supply-chain/deliveries?search=${so.so_number}`)).body?.data ?? [])[0];
  r = await api(who.finance, 'POST', `/supply-chain/deliveries/${delivery.id}/retry-invoice`, {});
  check(`${label}: finance re-bills the delivery from its page`, r.status === 200, `${msg(r)} handoff=${r.body?.data?.invoice_handoff?.status ?? r.body?.data?.invoice_handoff_status}`);
  [invoice] = (await draftInvoices(so)).filter((i) => i.status === 'draft');
  check(`${label}: a fresh draft invoice exists`, !!invoice, invoice ? invoice.total_amount : 'none');
  r = await api(who.finance, 'PATCH', `/invoices/${invoice.id}/finalize`, {});
  const total = r.body?.data?.total_amount;
  check(`${label}: re-billed invoice finalized`, r.status === 200, `${msg(r)} total=${total}`);

  r = await api(who.finance, 'POST', '/accounting/credit-notes', {
    type: 'customer', date: today(), customer_id: fixture.customer, invoice_id: invoice.id, is_vatable: true,
    lines: [{ account_id: fixture.revenue_account, description: 'Price concession on 10 pieces', amount: '125.00' }],
  });
  const note = r.body?.data;
  check(`${label}: credit note drafted`, r.status === 201 || r.status === 200, `${msg(r)} total=${note?.total_amount}`);
  if (note) {
    r = await api(who.finance, 'POST', `/accounting/credit-notes/${note.id}/finalize`, {});
    check(`${label}: credit note finalized`, r.status === 200, msg(r));
    r = await api(who.finance, 'POST', `/accounting/credit-notes/${note.id}/apply`, { amount: note.total_amount, invoice_id: invoice.id });
    check(`${label}: credit applied to the invoice`, r.status === 200, msg(r));
  }
  const balance = sql(`select balance from invoices where id=(select id from invoices where status in ('finalized','partial') and sales_order_id=(select id from sales_orders where so_number='${so.so_number}') order by id desc limit 1)`);
  const half = (Math.round(Number(balance) * 50) / 100).toFixed(2);
  r = await collect(invoice, half, `${RUN}-P1`);
  check(`${label}: a partial payment leaves the invoice partial`, r.status === 201 && sql(`select status from invoices where id=(select max(id) from invoices where sales_order_id=(select id from sales_orders where so_number='${so.so_number}'))`) === 'partial', `${msg(r)} paid ${half} of ${balance}`);
  const rest = sql(`select balance from invoices where id=(select max(id) from invoices where sales_order_id=(select id from sales_orders where so_number='${so.so_number}'))`);
  r = await collect(invoice, rest, `${RUN}-P2`);
  check(`${label}: the rest pays it off`, r.status === 201, `${msg(r)} paid ${rest}`);
  check(`${label}: the order closes with credit + payments covering it`, soStatus(so) === 'closed', soStatus(so));
  const unbalanced = sql("select count(*) from (select je.id from journal_entries je join journal_entry_lines l on l.journal_entry_id=je.id group by je.id having sum(l.debit) <> sum(l.credit)) x");
  check(`${label}: every journal entry balances`, unbalanced === '0', `unbalanced=${unbalanced}`);
  await shot(who.finance, `/accounting/invoices/${invoice.id}`, 'billing-invoice-paid');
}

async function complaintTo8D(so) {
  const label = 'Complaint';
  let r = await api(who.customer, 'POST', '/b2b/customer/complaints', { order_id: so.id, severity: 'high', description: 'Two bushings cracked on assembly.', affected_quantity: 2 });
  const complaint = r.body?.data;
  check(`${label}: customer files a complaint in the portal`, r.status === 201, `${msg(r)} ${complaint?.complaint_number}`);
  const internal = ((await api(who.cs, 'GET', `/crm/complaints?search=${complaint.complaint_number}`)).body?.data ?? [])[0];
  const ncrNumber = sql(`select n.ncr_number from customer_complaints c join non_conformance_reports n on n.id=c.ncr_id where c.complaint_number='${complaint.complaint_number}'`);
  check(`${label}: an NCR opened from the complaint`, !!ncrNumber, ncrNumber || 'none');
  const eightD = { d1_team: 'QC lead, production supervisor, CS', d2_problem: 'Cracked bushings at assembly.', d3_containment: 'Held remaining stock; 100% visual sort.', d4_root_cause: 'Cooling time too short after mold change.', d5_corrective_action: 'Restored cooling time; locked the setting.', d6_verification: 'Three lots passed crack inspection.', d7_prevention: 'Setting change needs QC sign-off.', d8_recognition: 'Team recognised at the weekly quality meeting.' };
  r = await api(who.cs, 'PATCH', `/crm/complaints/${internal.id}/8d`, eightD);
  check(`${label}: customer service writes the 8D`, r.status === 200, msg(r));
  r = await api(who.cs, 'POST', `/crm/complaints/${internal.id}/8d/finalize`, {});
  check(`${label}: 8D finalized`, r.status === 200, msg(r));
  const ncr = ((await api(who.qc, 'GET', `/quality/ncrs?search=${ncrNumber}`)).body?.data ?? [])[0];
  for (const [type, text] of [['corrective', 'Cooling time restored and locked.'], ['preventive', 'Mold-change checklist includes cooling time.']]) {
    await api(who.qc, 'POST', `/quality/ncrs/${ncr.id}/actions`, { action_type: type, description: text });
  }
  await api(who.qc, 'PATCH', `/quality/ncrs/${ncr.id}/disposition`, { disposition: 'use_as_is', root_cause: 'Cooling time too short.' });
  r = await api(who.qc, 'POST', `/quality/ncrs/${ncr.id}/close`, {});
  check(`${label}: QC closes the complaint's NCR`, r.status === 200, msg(r));
  r = await api(who.cs, 'POST', `/crm/complaints/${internal.id}/resolve`, {});
  check(`${label}: complaint resolved`, r.status === 200, msg(r));
  r = await api(who.cs, 'POST', `/crm/complaints/${internal.id}/close`, {});
  check(`${label}: complaint closed`, r.status === 200, msg(r));
  r = await api(who.customer, 'GET', `/b2b/customer/complaints/${complaint.id}/8d-report`);
  check(`${label}: customer reads the 8D report`, r.status === 200 && !!r.body?.data?.report?.finalized_at, msg(r));
}

/* ─── Main ────────────────────────────────────────────────────────── */

(async () => {
  let server;
  try {
    server = await setUp();
    await logins();
    const happy = await happyPath();
    const failed = await failedBatchIsReplacedOnce();
    await billingCorrections(failed);
    await complaintTo8D(happy);
  } catch (error) {
    check('run aborted', false, error.stack?.slice(0, 800) ?? String(error));
  } finally {
    report.summary = { passed: report.checks.length, failed: report.failures.length, server_errors: report.http_errors.length, page_errors: report.page_errors.length };
    fs.mkdirSync(OUT, { recursive: true });
    fs.writeFileSync(path.join(OUT, 'report.json'), JSON.stringify(report, null, 2));
    console.log(`\n${report.summary.passed} passed, ${report.summary.failed} failed, ${report.summary.server_errors} 5xx, ${report.summary.page_errors} page errors → ${OUT}/report.json`);
    await browser?.close();
    tearDown(server);
    process.exitCode = report.failures.length || report.http_errors.length ? 1 : 0;
  }
})();
