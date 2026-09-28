// Order-to-cash UI-driven chain run: every stage is FINISHED through the real
// UI — real pages, real form fills, real button clicks, real modal confirms,
// in strict chain order, ending in terminal states (WO completed, QC passed,
// deliveries customer-confirmed, invoices paid, SO closed).
//
// In-page `fetch` is used only to READ state (hash ids, numbers) and to verify
// results; every business mutation happens by clicking what the actor would
// click. One browser context per role-based account — no superadmin.
//
//   node scripts/o2c-ui-chain.cjs        # from the repo root, stack running
//
// Self-contained like scripts/o2c-headless.cjs: dedicated database
// ogami_test_o2c_browser_o2cui<run>, temporary API + Vite proxy, torn down on
// success. O2CUI_KEEP=1 keeps everything for inspection.
const path = require('node:path');
const fs = require('node:fs');
const { execFileSync } = require('node:child_process');
const { pathToFileURL } = require('node:url');
const { createRequire } = require('node:module');

const ROOT = path.resolve(__dirname, '..');
const SPA = path.join(ROOT, 'spa');
const { chromium } = require(path.join(SPA, 'node_modules/playwright'));
const spaRequire = createRequire(path.join(SPA, 'package.json'));

const RUN = (process.env.O2CUI_RUN_ID || `O2CUI${Date.now().toString(36)}`).toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 16);
const DB = `ogami_test_o2c_browser_o2cui${RUN.toLowerCase()}`;
const API_PORT = Number(process.env.O2CUI_API_PORT || 8231);
const SPA_PORT = Number(process.env.O2CUI_SPA_PORT || 5231);
const BASE = `http://127.0.0.1:${SPA_PORT}`;
const OUT = process.env.O2CUI_OUTPUT || path.join('/tmp', `o2c-ui-chain-${RUN}`);
const KEEP = process.env.O2CUI_KEEP === '1';
const FIXTURE_IN_CONTAINER = `/tmp/o2c-fixture-${RUN}.json`;
const ENV = {
  DB_DATABASE: DB, CACHE_STORE: 'array', QUEUE_CONNECTION: 'sync', MAIL_MAILER: 'array',
  BROADCAST_CONNECTION: 'log', SESSION_DRIVER: 'database', APP_URL: BASE,
  SANCTUM_STATEFUL_DOMAINS: `127.0.0.1:${SPA_PORT},localhost:${SPA_PORT}`,
  O2C_BROWSER_DB: DB, O2C_RUN_ID: RUN, O2C_FIXTURE_PATH: FIXTURE_IN_CONTAINER,
};

const report = { run_id: RUN, database: DB, base_url: BASE, mode: 'ui-driven', checks: [], failures: [], http_errors: [], page_errors: [] };
let fixture;

function check(name, ok, evidence = '') {
  const text = typeof evidence === 'string' ? evidence : JSON.stringify(evidence);
  (ok ? report.checks : report.failures).push({ name, evidence: text });
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${text ? ` — ${text.slice(0, 260)}` : ''}`);
  return ok;
}

/* ─── Environment (same harness as o2c-headless.cjs) ──────────────── */

function compose(args) {
  return execFileSync('docker', ['compose', ...args], { cwd: ROOT, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'], maxBuffer: 64 * 1024 * 1024 });
}
const envFlags = () => Object.entries(ENV).flatMap(([k, v]) => ['-e', `${k}=${v}`]);
const artisan = (...args) => compose(['exec', '-T', ...envFlags(), 'api', 'php', '-d', 'memory_limit=1G', 'artisan', ...args]);
const psql = (sqlText, db = 'postgres') => compose(['exec', '-T', 'db', 'psql', '-U', 'ogami', '-d', db, '-At', '-F', '|', '-c', sqlText]).trim();
const sql = (query) => psql(query, DB);
const apiIp = () => execFileSync('docker', ['inspect', '-f', '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}', 'ogami-api'], { encoding: 'utf8' }).trim();

function stopApiServer() {
  for (const pattern of [`port=${API_PORT}`, `0.0.0.0:${API_PORT}`]) {
    try { compose(['exec', '-T', 'api', 'pkill', '-f', pattern]); } catch { /* not running */ }
  }
}

async function setUp() {
  fs.mkdirSync(OUT, { recursive: true });
  stopApiServer();
  psql(`CREATE DATABASE ${DB} OWNER ogami;`);
  artisan('migrate', '--force');
  artisan('tinker', '--execute', "require 'tests/Browser/o2c_fixture.php';");
  compose(['cp', `api:${FIXTURE_IN_CONTAINER}`, path.join(OUT, 'fixture.json')]);
  fixture = JSON.parse(fs.readFileSync(path.join(OUT, 'fixture.json'), 'utf8'));
  compose(['exec', '-d', ...envFlags(), 'api', 'php', 'artisan', 'serve', '--no-reload', '--host=0.0.0.0', `--port=${API_PORT}`]);
  const target = `http://${apiIp()}:${API_PORT}`;
  let ready = false;
  for (let i = 0; i < 60; i++) {
    try {
      const csrf = await fetch(`${target}/sanctum/csrf-cookie`);
      const user = await fetch(`${target}/api/v1/auth/user`, { headers: { Accept: 'application/json' } });
      if (csrf.status < 500 && user.status < 500) { ready = true; break; }
    } catch { /* booting */ }
    await new Promise((r) => setTimeout(r, 1000));
  }
  if (!ready) throw new Error(`The API on :${API_PORT} never answered against ${DB}.`);
  const vite = await import(pathToFileURL(path.join(SPA, 'node_modules/vite/dist/node/index.js')).href);
  process.chdir(SPA);
  const server = await vite.createServer({
    configFile: path.join(SPA, 'vite.config.ts'), root: SPA, logLevel: 'error',
    css: { postcss: { plugins: [spaRequire('tailwindcss')(path.join(SPA, 'tailwind.config.ts')), spaRequire('autoprefixer')()] } },
    server: {
      host: '127.0.0.1', port: SPA_PORT, strictPort: true,
      hmr: { host: '127.0.0.1', port: SPA_PORT, clientPort: SPA_PORT },
      proxy: { '/api': { target, changeOrigin: false }, '/sanctum': { target, changeOrigin: false } },
    },
  });
  await server.listen();
  return server;
}

async function tearDown(server) {
  try { await server?.close(); } catch { /* already closed */ }
  const dirty = report.failures.length > 0 || report.http_errors.length > 0 || report.page_errors.length > 0;
  if (KEEP || dirty) {
    if (dirty && !KEEP) console.log(`Run was not clean — keeping ${DB} and API :${API_PORT} for inspection.`);
    console.log(`Kept ${DB} and API :${API_PORT} — start the SPA with: npm run dev`);
    return;
  }
  stopApiServer();
  try { compose(['exec', '-T', 'api', 'rm', '-f', FIXTURE_IN_CONTAINER]); } catch { /* gone */ }
  try {
    psql(`SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname='${DB}' AND pid <> pg_backend_pid();`);
    psql(`DROP DATABASE IF EXISTS ${DB};`);
  } catch (e) { console.log(`Could not drop ${DB}: ${e.message}`); }
}

/* ─── Browser helpers ─────────────────────────────────────────────── */

let browser;
const who = {};
const sessions = new WeakMap();
const emailOf = (page) => sessions.get(page.context())?.email ?? 'crm@ogami.test';
const checkOf = (page) => sessions.get(page.context())?.sessionCheck ?? '/auth/user';

async function signIn(page, email, sessionCheck) {
  // The login form disables its inputs while a 429 cooldown runs; back-to-back
  // runs log in many accounts from one IP, so wait OUT the cooldown instead of
  // timing out on a disabled field. The form itself can also take a moment on
  // a cold Vite transform, so retry the navigation until the fields render.
  const emailInput = page.getByLabel('Email');
  let visible = false;
  for (let i = 0; i < 6 && !visible; i += 1) {
    try {
      await page.goto('/sign-in', { waitUntil: 'domcontentloaded', timeout: 20_000 });
      await page.waitForTimeout(500);
      // A stale session (cached SPA state from an earlier run) bounces /sign-in
      // back to the dashboard. Clear every client-side artifact and hard-reload.
      if (!page.url().includes('/sign-in')) {
        await page.evaluate(() => { localStorage.clear(); sessionStorage.clear(); }).catch(() => {});
        await contextClearCookies(page);
        await page.goto('/sign-in', { waitUntil: 'domcontentloaded', timeout: 20_000 });
      }
      await emailInput.waitFor({ state: 'visible', timeout: 8_000 });
      visible = true;
    } catch {
      const body = (await page.locator('body').innerText().catch(() => '')).replace(/\s+/g, ' ').slice(0, 200);
      console.log(`  sign-in form not up yet (attempt ${i + 1}): ${body || '(empty page)'}`);
      await page.waitForTimeout(3_000);
    }
  }
  if (!visible) throw new Error(`The sign-in form never rendered for ${email} at ${page.url()}.`);
  for (let i = 0; i < 18 && !(await emailInput.isEnabled()); i += 1) {
    if (i === 0) console.log(`  auth cooldown active — waiting for the sign-in form to re-enable…`);
    await page.waitForTimeout(5_000);
    await page.reload().catch(() => {});
    await emailInput.waitFor({ state: 'visible', timeout: 30_000 });
  }
  await emailInput.fill(email);
  await page.getByLabel('Password', { exact: true }).fill('password');
  await page.getByRole('button', { name: /sign in/i }).click();
  await page.waitForURL((u) => !u.pathname.startsWith('/sign-in'), { timeout: 30_000 });
  await page.waitForTimeout(400);
  const me = await api(page, 'GET', sessionCheck, undefined, {}, { probe: false });
  if (me.status !== 200) throw new Error(`Login as ${email} left no session (GET ${sessionCheck} → ${me.status}).`);
}

async function login(email, sessionCheck = '/auth/user') {
  browser ??= await chromium.launch({ headless: true });
  const context = await browser.newContext({ baseURL: BASE, viewport: { width: 1440, height: 1000 }, timezoneId: 'Asia/Manila' });
  await context.routeWebSocket((url) => new URL(url).pathname.startsWith('/app/'), (socket) => {
    socket.onMessage((message) => {
      let frame; try { frame = JSON.parse(String(message)); } catch { return; }
      if (frame.event === 'pusher:ping') socket.send(JSON.stringify({ event: 'pusher:pong', data: {} }));
      if (frame.event === 'pusher:subscribe') socket.send(JSON.stringify({ event: 'pusher_internal:subscription_succeeded', channel: frame.data?.channel, data: '{}' }));
    });
    socket.send(JSON.stringify({ event: 'pusher:connection_established', data: JSON.stringify({ socket_id: '1.1', activity_timeout: 120 }) }));
  });
  const page = await context.newPage();
  sessions.set(context, { email, sessionCheck });
  page.on('pageerror', (e) => report.page_errors.push(`${email}: ${e.message}`));
  page.on('response', (r) => {
    if (r.status() >= 500 && r.url().includes('/api/')) report.http_errors.push(`${email} ${r.status()} ${r.request().method()} ${r.url().replace(BASE, '')}`);
  });
  // The lost-session race that hits mid-chain can hit the sign-in itself: the
  // form posts, the SPA lands on the dashboard, and the freshly rotated
  // session is already gone. Retry rather than calling that a product failure.
  let lastError;
  for (let attempt = 1; attempt <= 3; attempt += 1) {
    try {
      await signIn(page, email, sessionCheck);
      return page;
    } catch (error) {
      lastError = error;
      await page.waitForTimeout(1_500 * attempt);
    }
  }
  throw lastError;
}

/** Read-only: search endpoints and verify results. Never mutates. */
async function api(page, method, route, body, headers = {}, options = {}) {
  const send = () => page.evaluate(async ({ method, route, body, headers }) => {
    const xsrf = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
    const r = await fetch(`/api/v1${route}`, {
      method, credentials: 'include',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf, ...headers },
      body: body === undefined ? undefined : JSON.stringify(body),
    });
    const type = r.headers.get('content-type') || '';
    return { status: r.status, body: type.includes('json') ? await r.json().catch(() => null) : null };
  }, { method, route, body, headers });
  let result;
  for (let attempt = 0; attempt < 2; attempt += 1) {
    try { result = await send(); break; } catch (error) {
      if (attempt === 1) throw error;
      await page.waitForTimeout(500);
      if (new URL(page.url()).pathname === '/sign-in') await signIn(page, emailOf(page), checkOf(page));
    }
  }
  if (result.status === 419 && !options.csrfRetried) {
    await page.evaluate(() => fetch('/sanctum/csrf-cookie', { credentials: 'include' }).catch(() => {}));
    return api(page, method, route, body, headers, { ...options, csrfRetried: true });
  }
  if (result.status === 401 && options.probe !== false) {
    await signIn(page, emailOf(page), checkOf(page));
    return api(page, method, route, body, headers, { probe: false, csrfRetried: false });
  }
  return result;
}

async function contextClearCookies(page) {
  await page.context().clearCookies();
}

const today = () => new Date(Date.now() + 8 * 3600e3).toISOString().slice(0, 10);
const inDays = (n) => new Date(Date.now() + 8 * 3600e3 + n * 864e5).toISOString().slice(0, 10);

async function open(page, route) {
  await page.goto(route);
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.waitForTimeout(300);
}

async function shot(page, route, name) {
  if (route) await open(page, route);
  await page.waitForTimeout(400);
  await page.screenshot({ path: path.join(OUT, `${name}.png`), fullPage: true });
}

/* ─── UI verbs ────────────────────────────────────────────────────── */

async function pageButton(page, name) {
  const btn = page.getByRole('button', { name, exact: true }).first();
  await btn.waitFor({ state: 'visible', timeout: 15_000 });
  await btn.click();
}

async function dialogSelect(page, index, option) {
  const sel = page.getByRole('dialog').locator('select').nth(index);
  await sel.waitFor({ state: 'visible', timeout: 10_000 });
  if (typeof option === 'number') await sel.selectOption({ index: option });
  else await sel.selectOption(option);
}

async function modalButton(page, name) {
  const btn = page.getByRole('dialog').getByRole('button', { name, exact: true }).first();
  await btn.waitFor({ state: 'visible', timeout: 10_000 });
  await btn.click();
}

async function selectByRegExp(page, sel, pattern) {
  const value = await sel.locator('option').evaluateAll(
    (nodes, re) => (nodes.find((n) => re.test(n.textContent ?? '')) ?? {}).value,
    pattern.source,
  );
  if (!value) throw new Error(`No option matching /${pattern.source}/`);
  await sel.selectOption(value);
}

/** Read the inspection's live status via a read-only detail API call. */
async function inspectionStatus(page, inspectionHashId) {
  const detail = (await api(page, 'GET', `/quality/inspections/${inspectionHashId}`)).body?.data;
  return { status: detail?.status, mode: detail?.inspection_mode };
}

/** Wait for a toast; every UI mutation ends here so a failed action is read.
 *  react-hot-toast renders role=status; other status regions (draft-restore
 *  banners) exist too, so match the toast BY its text pattern. */
async function expectToast(page, pattern, label) {
  let seen = null;
  try {
    const toast = page.getByRole('status').filter({ hasText: pattern }).last();
    await toast.waitFor({ state: 'visible', timeout: 12_000 });
    seen = (await toast.innerText()).trim();
  } catch {
    const all = await page.getByRole('status').allInnerTexts().catch(() => []);
    await page.waitForLoadState('networkidle').catch(() => {});
    return check(label, false, `no toast matching ${pattern} — visible status regions: ${all.join(' | ').slice(0, 180) || 'none'}`);
  }
  await page.waitForLoadState('networkidle').catch(() => {});
  const isError = /failed|refused|cannot|could not|error|invalid/i.test(seen);
  return check(label, !isError, seen.slice(0, 180));
}

/** Status chip next to the mono document number in the page header. */
async function statusOf(page, number) {
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.waitForTimeout(300);
  const el = page.locator(`span.font-mono:has-text("${number}")`).first();
  const text = (await el.count()) ? await el.locator('xpath=..').innerText() : await page.locator('body').innerText();
  return text.match(/Draft|Confirmed|In production|In progress|Planned|Partially delivered|Delivered|Awaiting review|Passed|Failed|Scheduled|Loading|In transit|Finalized|Partial|Paid|Closed|Completed|Open|Approved|Invoiced|Cancelled/i)?.[0] ?? '';
}

/** Open a detail page by clicking its row in a list; fall back to direct nav. */
async function openDetail(page, listRoute, needle, detailPattern, fallbackRoute) {
  await open(page, listRoute);
  const cell = page.getByText(needle).first();
  try {
    await cell.click({ timeout: 5000 });
    await page.waitForURL(detailPattern, { timeout: 10_000 });
  } catch {
    if (fallbackRoute) await open(page, fallbackRoute);
  }
  await page.waitForLoadState('networkidle').catch(() => {});
}

/* ─── Chain steps (UI-only mutations) ─────────────────────────────── */

let SO_ID; let SO_NUMBER; let WO_NUMBER;

// 1 — Sales officer creates AND confirms the order from the form. The inline
// confirm plans the order; a planned work order appears.
async function step1SalesOrder() {
  const label = '1 · Sales';
  const page = who.sales;
  await open(page, '/crm/sales-orders/create');
  await page.locator('select[name="customer_id"]').selectOption({ label: `O2C customer ${RUN}` });
  const productSelect = page.locator('select[name="items.0.product_id"]');
  const productValue = await productSelect.locator('option', { hasText: fixture.product_code }).first().getAttribute('value');
  await productSelect.selectOption(productValue);
  await page.locator('input[name="items.0.quantity"]').fill('200');
  await page.locator('input[name="items.0.delivery_date"]').fill(inDays(14));
  await pageButton(page, 'Save & confirm');
  // Strict navigation: the detail URL must NOT be /create. A validation or
  // server failure keeps us here, so capture the toast and stop the run.
  const detailUrl = /\/crm\/sales-orders\/(?!create)[^/]+$/;
  try {
    await page.waitForURL(detailUrl, { timeout: 30_000 });
  } catch {
    const body = (await page.locator('body').innerText()).replace(/\s+/g, ' ').slice(0, 400);
    check(`${label}: order created AND confirmed from the form`, false, `still on ${page.url()} — ${body}`);
    throw new Error('Sales order did not navigate to its detail page; aborting so the failure is not cascaded.');
  }
  await open(page, page.url());
  SO_ID = page.url().split('/').pop();
  SO_NUMBER = (await page.locator('body').innerText()).match(/SO-\d{6}-\d{4}/)?.[0];
  check(`${label}: order created AND confirmed from the form`, /^SO-/.test(SO_NUMBER ?? ''), `${SO_NUMBER} · ${page.url()}`);
  const soDetail = (await api(page, 'GET', `/crm/sales-orders/${SO_ID}`)).body?.data;
  check(`${label}: status is confirmed`, soDetail?.status === 'confirmed', soDetail?.status);

  WO_NUMBER = sql(`select wo_number from work_orders where sales_order_id=(select id from sales_orders where so_number='${SO_NUMBER}') order by id desc limit 1`);
  check(`${label}: confirming the order drafted a work order`, /^WO-/.test(WO_NUMBER), WO_NUMBER);

  // No jumping: with the WO still planned, the warehouse must NOT be able to
  // issue material to it — the issue form lists no such work order yet.
  const wh = who.warehouse;
  await open(wh, '/inventory/material-issues/create');
  await wh.locator('select[name="work_order_id"]').waitFor({ state: 'visible', timeout: 15_000 });
  const optionCount = await wh.locator('select[name="work_order_id"] option', { hasText: WO_NUMBER }).count();
  check(`${label}: NO JUMPING — a planned WO is not yet issuable from the warehouse form`, optionCount === 0, `${WO_NUMBER} options=${optionCount}`);
}

// 2 — PPC runs the scheduler. Segregation of duties: ppc_head PROPOSES
// (mrp.schedule) but does NOT hold production.schedule.confirm — the confirm
// button must be absent for PPC and present for the production manager.
async function step2Schedule() {
  const label = '2 · PPC';
  const page = who.ppc;
  await open(page, '/production/schedule');
  await pageButton(page, 'Run scheduler');
  const toastSeen = await expectToast(page, /proposed \d+ schedules?/, `${label}: PPC ran the scheduler and it proposed schedules`);
  if (!toastSeen) throw new Error('Scheduler proposed nothing; aborting.');
  const confirmForPpc = await page.getByRole('button', { name: /Confirm \d+ schedule/ }).count();
  check(`${label}: NO JUMPING — PPC cannot confirm the schedule (permission boundary)`, confirmForPpc === 0, `confirm buttons visible to ppc_head=${confirmForPpc}`);

  // The proposal lives in the page's own state, so the production manager
  // runs the scheduler on their own session — run + confirm is one desk.
  const prod = who.prod;
  await open(prod, '/production/schedule');
  const runResponse = prod.waitForResponse((r) => r.url().includes('/scheduler/run') && r.request().method() === 'POST', { timeout: 20_000 });
  await pageButton(prod, 'Run scheduler');
  await runResponse;
  const proposal = prod.getByRole('button', { name: /Confirm \d+ schedule/ });
  await proposal.waitFor({ state: 'visible', timeout: 20_000 });
  const count = (await proposal.innerText()).match(/Confirm (\d+)/)?.[1];
  check(`${label}: the production manager sees the proposal on their own run`, Number(count) >= 1, `proposed=${count}`);
  const confirmResponse = prod.waitForResponse((r) => r.url().includes('/scheduler/confirm') && r.request().method() === 'POST', { timeout: 20_000 });
  await proposal.click();
  const confirmResult = await confirmResponse;
  const confirmedOk = confirmResult.status() === 200;
  await prod.waitForLoadState('networkidle').catch(() => {});
  check(`${label}: production manager confirmed the schedule`, confirmedOk, `HTTP ${confirmResult.status()}`);
}

// 3 — Production manager confirms the WO (machine + mold) and starts it.
async function step3ConfirmAndStart(woId) {
  const label = '3 · Production';
  const page = who.prod;
  await openDetail(page, `/production/work-orders?search=${WO_NUMBER}`, WO_NUMBER, /\/production\/work-orders\/[^/]+$/, `/production/work-orders/${woId}`);
  // Confirming the SCHEDULE already binds machine + mold and moves the WO to
  // 'confirmed'. The WO-level Confirm dialog is only for unscheduled WOs.
  let status = await statusOf(page, WO_NUMBER);
  if (/Planned/i.test(status)) {
    await pageButton(page, 'Confirm');
    await dialogSelect(page, 0, { index: 1 }); // Machine
    await dialogSelect(page, 1, { index: 1 }); // Mold
    const confirmWo = page.waitForResponse((r) => r.url().includes(`/production/work-orders/${woId}/confirm`) && r.request().method() === 'POST', { timeout: 20_000 });
    await modalButton(page, 'Confirm');
    const confirmWoResult = await confirmWo;
    await page.waitForLoadState('networkidle').catch(() => {});
    check(`${label}: WO confirmed with machine + mold from the dialog`, confirmWoResult.status() === 200, `HTTP ${confirmWoResult.status()}`);
  } else {
    const woNow = (await api(page, 'GET', `/production/work-orders/${woId}`)).body?.data;
    check(`${label}: schedule confirm already bound a machine and confirmed the WO`, woNow?.status === 'confirmed' && !!woNow?.machine?.id, `${woNow?.status} machine=${woNow?.machine?.machine_code}`);
  }
  await page.waitForTimeout(500);

  const startWo = page.waitForResponse((r) => r.url().includes(`/production/work-orders/${woId}/start`) && r.request().method() === 'POST', { timeout: 20_000 });
  await pageButton(page, 'Start');
  await modalButton(page, 'Start');
  const startWoResult = await startWo;
  await page.waitForLoadState('networkidle').catch(() => {});
  check(`${label}: WO started from the header button`, startWoResult.status() === 200, `HTTP ${startWoResult.status()}`);
  await page.waitForTimeout(500);
  const woAfter = (await api(page, 'GET', `/production/work-orders/${woId}`)).body?.data;
  check(`${label}: status is in progress`, woAfter?.status === 'in_progress', woAfter?.status);
}

// 4 — Warehouse issues the reserved material to the running WO.
async function step4IssueMaterial(woId, woDbId) {
  const label = '4 · Warehouse';
  const page = who.warehouse;
  await open(page, `/inventory/material-issues/create?work_order_id=${woId}`);
  await page.locator('select[name="work_order_id"]').selectOption({ label: new RegExp(WO_NUMBER) }).catch(async () => {
    const value = await page.locator('select[name="work_order_id"] option', { hasText: WO_NUMBER }).getAttribute('value');
    await page.locator('select[name="work_order_id"]').selectOption(value);
  });
  const itemSelect = page.locator('select[name="items.0.item_id"]');
  const resinValue = await itemSelect.locator('option', { hasText: 'O2C resin' }).first().getAttribute('value');
  await itemSelect.selectOption(resinValue);
  await page.waitForTimeout(800); // location options load per item
  await page.locator('select[name="items.0.location_id"]').selectOption({ index: 1 });
  await page.waitForTimeout(300);
  await page.locator('input[name="items.0.quantity_issued"]').fill('4.060');
  await pageButton(page, 'Create issue');
  const issueResponse = page.waitForResponse((r) => r.url().endsWith('/inventory/material-issues') && r.request().method() === 'POST', { timeout: 20_000 });
  // The success toast is the first thing shown; read it before it expires.
  await expectToast(page, /created|issue/i, `${label}: material issue recorded through the form (4.060 kg resin)`);
  const issueResult = await issueResponse;
  check(`${label}: the issue POST succeeded`, issueResult.status() === 201 || issueResult.status() === 200, `HTTP ${issueResult.status()}`);
  const movements = sql(`select count(*) from material_issue_slips where work_order_id=${woDbId} and status='issued'`);
  check(`${label}: the issue landed (slip rows)`, Number(movements) >= 1, `rows=${movements}`);
}

// 5 — Production records two output batches through the form, then completes.
async function step5ProduceOutputs(woId) {
  const label = '5 · Production';
  const page = who.prod;
  for (const [good, rejectCount, defectCount] of [[150, 3, 3], [50, 0, 0]]) {
    await open(page, `/production/work-orders/${woId}`);
    await pageButton(page, 'Record output');
    await page.waitForURL(/\/record-output$/, { timeout: 15_000 });
    await page.getByLabel('Good count').fill(String(good));
    if (rejectCount > 0) {
      await page.getByRole('button', { name: 'Add defect' }).click();
      await page.locator('select[name="defects.0.defect_type_id"]').selectOption({ index: 1 });
      await page.locator('input[name="defects.0.count"]').fill(String(defectCount));
    }
    const shift = page.locator('select[name="shift"]');
    if (await shift.count()) await shift.selectOption({ index: 1 }).catch(() => {});
    await pageButton(page, 'Record');
    await expectToast(page, /record/i, `${label}: output recorded through the form (${good} good / ${rejectCount} reject)`);
  }
  await open(page, `/production/work-orders/${woId}`);
  const completeWo = page.waitForResponse((r) => r.url().includes(`/production/work-orders/${woId}/complete`) && r.request().method() === 'POST', { timeout: 20_000 });
  await pageButton(page, 'Complete');
  await modalButton(page, 'Complete');
  const completeWoResult = await completeWo;
  await page.waitForLoadState('networkidle').catch(() => {});
  check(`${label}: WO completed from the header button`, completeWoResult.status() === 200, `HTTP ${completeWoResult.status()}`);
  const woDone = (await api(page, 'GET', `/production/work-orders/${woId}`)).body?.data;
  check(`${label}: WO terminal state is completed`, woDone?.status === 'completed', woDone?.status);
  const outputs = sql(`select count(*) from work_order_outputs where work_order_id=(select id from work_orders where wo_number='${WO_NUMBER}')`);
  check(`${label}: two output batches on the WO`, outputs === '2', `outputs=${outputs}`);
}

// 6 — QC inspector measures, saves, and completes every inspection (UI).
// Handles BOTH capture modes: the per-unit measurement table (Save (n) +
// Complete) and the lot-checklist capture panel (Tick all, defect count,
// piece measurements, Submit result).
async function measureInspectionUI(page, inspectionNumber, { good = true } = {}) {
  // Resolve the hash id up front (read-only) so navigation cannot flake.
  const found = ((await api(page, 'GET', `/quality/inspections?search=${inspectionNumber}`)).body?.data ?? [])
    .find((i) => i.inspection_number === inspectionNumber);
  if (!found) throw new Error(`Inspection ${inspectionNumber} not found in the list API.`);
  await open(page, `/quality/inspections/${found.id}`);
  // Either capture UI must appear before we decide the mode.
  await page.getByRole('button', { name: /Submit result|^Save \(/ }).first()
    .waitFor({ state: 'visible', timeout: 20_000 })
    .catch(async () => {
      const body = (await page.locator('body').innerText().catch(() => '')).replace(/\s+/g, ' ').slice(0, 250);
      throw new Error(`Neither capture UI rendered for ${inspectionNumber} at ${page.url()} — ${body}`);
    });

  const lotPanel = page.getByRole('button', { name: 'Submit result' });
  const isLotMode = (await lotPanel.count()) > 0;

  if (isLotMode) {
    // Section 1: tick every checklist item OK (with its confirm dialog).
    const tickAll = page.getByRole('button', { name: 'Tick all' });
    if ((await tickAll.count()) && (await tickAll.first().isVisible().catch(() => false))) {
      await tickAll.first().click();
      await modalButton(page, 'OK').catch(() => {});
    }
    // Section 2: the counted AQL sample's defective pieces.
    await page.getByLabel('Defective pieces found').fill(good ? '0' : '8');
    // Section 3: per-piece measurements of the toleranced dimensions.
    const numeric = page.locator('input[type="number"][aria-label*=", piece "]');
    const rows = await numeric.count();
    for (let i = 0; i < rows; i += 1) await numeric.nth(i).fill(good ? '10.010' : '10.200');
    // Submit from the panel (single POST with complete=true).
    const lotPost = page.waitForResponse((r) => r.url().includes('/lot-result') && r.request().method() === 'POST', { timeout: 20_000 });
    await lotPanel.first().click();
    const lotResult = await lotPost;
    await page.waitForLoadState('networkidle').catch(() => {});
    await expectToast(page, /review|PASSED|FAILED/i, `6 · QC: ${inspectionNumber} lot result submitted through the capture panel (${rows} piece rows)`);
    check(`6 · QC: ${inspectionNumber} lot-result POST accepted`, lotResult.status() === 200 || lotResult.status() === 201, `HTTP ${lotResult.status()}`);
  } else {
    const numeric = page.locator('input[aria-label^="Measured value —"]');
    const rows = await numeric.count();
    for (let i = 0; i < rows; i += 1) await numeric.nth(i).fill(good ? '10.010' : '10.200');
    const shortcut = page.getByRole('button', { name: /Pass \d+ open manual check/ });
    if (await shortcut.count()) await shortcut.click();
    await pageButton(page, /^Save \(/);
    await expectToast(page, /saved/i, `6 · QC: ${inspectionNumber} measurements saved through the form (${rows} numeric rows)`);
    await pageButton(page, 'Complete');
    await modalButton(page, 'Complete');
    await page.waitForTimeout(600);
  }

  const hashId = found.id;
  const { status } = await inspectionStatus(page, hashId);
  return status;
}

async function step6QC(woDbId) {
  const label = '6 · QC';
  const page = who.qc;
  const numbers = sql(`select i.inspection_number from inspections i join work_order_outputs o on o.id=i.work_order_output_id where o.work_order_id=${woDbId} and i.stage='outgoing' order by i.id`).split('\n').filter(Boolean);
  check(`${label}: outgoing inspections auto-created per output batch`, numbers.length === 2, numbers.join(', '));
  for (const number of numbers) {
    const status = await measureInspectionUI(page, number);
    check(`${label}: ${number} completed from the form → awaiting review`, status === 'awaiting_review', status);
  }
  const inProcess = sql(`select inspection_number from inspections where entity_type='work_order' and entity_id=${woDbId} and stage='in_process' and status in ('draft','in_progress')`);
  if (/^QC-/.test(inProcess)) {
    const status = await measureInspectionUI(page, inProcess);
    check(`${label}: in-process ${inProcess} completed from the form → ${status}`, status === 'awaiting_review' || status === 'passed', status);
  }
  return numbers;
}

// 7 — Production manager (checker) approves both results.
async function step7CheckerReview(numbers) {
  const label = '7 · Checker';
  const page = who.prod;
  for (const number of numbers) {
    await openDetail(page, `/quality/inspections?search=${number}`, number, /\/quality\/inspections\/[^/]+$/, null);
    await pageButton(page, 'Approve pass');
    await modalButton(page, 'Approve pass');
    await expectToast(page, /approved/i, `${label}: ${number} approved through the maker-checker dialog`);
    const hashId = page.url().split('/').pop();
    const { status } = await inspectionStatus(page, hashId);
    check(`${label}: ${number} terminal state is passed`, status === 'passed', status);
  }
}

// 8 — ImpEx assigns van + driver, then walks the delivery to delivered.
async function step8Dispatch(deliveries) {
  const label = '8 · ImpEx';
  const page = who.impex;
  for (const delivery of deliveries) {
    await openDetail(page, `/supply-chain/deliveries?search=${delivery.number}`, delivery.number, /\/supply-chain\/deliveries\/[^/]+$/, `/supply-chain/deliveries/${delivery.id}`);
    await pageButton(page, 'Assign driver & vehicle');
    await dialogSelect(page, 0, { label: /O2C van/ }).catch(() => dialogSelect(page, 0, { index: 1 }));
    await dialogSelect(page, 1, { index: 1 });
    await page.getByRole('dialog').locator('textarea').first().fill('UI chain run — first available van and driver.');
    await modalButton(page, 'Save assignment');
    await expectToast(page, /assign|saved/i, `${label}: ${delivery.number} van + driver assigned through the dialog`);
    await page.waitForTimeout(400);

    // Departure re-checks a physical reservation of the inspected lot; the
    // dispatch desk reserves it from the delivery page before loading. The
    // click can land before React attaches handlers, so retry with reloads.
    await open(page, `/supply-chain/deliveries/${delivery.id}`);
    let reserveResult = null;
    for (let attempt = 0; attempt < 3 && !reserveResult; attempt += 1) {
      try {
        const reserveBtn = page.getByRole('button', { name: 'Reserve shipment stock' });
        if ((await reserveBtn.count()) === 0) break; // already reserved
        const reservePost = page.waitForResponse((r) => r.url().includes(`/supply-chain/deliveries/${delivery.id}/reserve-stock`) && r.request().method() === 'POST', { timeout: 20_000 });
        await reserveBtn.first().click();
        reserveResult = await reservePost;
      } catch {
        await open(page, `/supply-chain/deliveries/${delivery.id}`);
      }
    }
    check(`${label}: ${delivery.number} inspected lot reserved from the page`, !!reserveResult && (reserveResult.status() === 200 || reserveResult.status() === 201), reserveResult ? `HTTP ${reserveResult.status()}` : 'no reservation POST observed');
    await open(page, `/supply-chain/deliveries/${delivery.id}`);

    for (const next of ['loading', 'in_transit', 'delivered']) {
      const labelRe = new RegExp(`^Mark ${next.replace('_', ' ')}`, 'i');
      let advanceResult = null;
      for (let attempt = 0; attempt < 3 && !advanceResult; attempt += 1) {
        try {
          const advance = page.waitForResponse((r) => r.url().includes(`/supply-chain/deliveries/${delivery.id}/status`) && r.request().method() === 'PATCH', { timeout: 20_000 });
          await pageButton(page, labelRe);
          advanceResult = await advance;
        } catch {
          // The button may still be mounting after a query invalidation; reload
          // and retry rather than failing the whole chain.
          await open(page, `/supply-chain/deliveries/${delivery.id}`);
        }
      }
      if (!advanceResult) throw new Error(`The ${next} button never accepted a click on ${delivery.number}.`);
      await page.waitForLoadState('networkidle').catch(() => {});
      await open(page, `/supply-chain/deliveries/${delivery.id}`);
      check(`${label}: ${delivery.number} → ${next}`, advanceResult.status() === 200, `HTTP ${advanceResult.status()}`);
    }
    const status = (await api(page, 'GET', `/supply-chain/deliveries/${delivery.id}`)).body?.data?.status;
    check(`${label}: ${delivery.number} terminal state is delivered`, status === 'delivered', status);
  }
}

// 9 — Driver uploads the receipt photo through the driver flow.
async function step9DriverProof(deliveries) {
  const label = '9 · Driver';
  const page = who.driver;
  const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');
  for (const delivery of deliveries) {
    await openDetail(page, '/driver', delivery.number, /\/driver\/[^/]+$/, `/driver/${delivery.id}`);
    await page.getByRole('button', { name: /Capture receipt photo/i }).click();
    await page.waitForURL(/\/driver\/[^/]+\/photo$/, { timeout: 15_000 });
    const inputs = page.locator('input[type="file"]');
    await inputs.nth(1).setInputFiles({ name: `receipt-${delivery.number}.png`, mimeType: 'image/png', buffer: png });
    await page.getByRole('button', { name: /Upload photo/i }).click();
    await expectToast(page, /upload|success/i, `${label}: receipt photo uploaded for ${delivery.number} through the driver flow`);
  }
}

// 10 — Customer confirms receipt in the portal with receiver details.
async function step10CustomerConfirm(deliveries) {
  const label = '10 · Customer';
  const page = who.customer;
  for (const delivery of deliveries) {
    await openDetail(page, '/portal/customer/deliveries', delivery.number, /\/portal\/customer\/deliveries\/[^/]+$/, `/portal/customer/deliveries/${delivery.id}`);
    await page.getByRole('button', { name: /Confirm Receipt/i }).first().click();
    await page.getByLabel('Received by').fill('Juan Dela Cruz');
    await page.getByLabel('Position').fill('Warehouse Staff');
    await page.getByRole('button', { name: /Confirm receipt/i }).last().click();
    await expectToast(page, /confirm|thank/i, `${label}: ${delivery.number} receipt confirmed in the portal with receiver details`);
    const status = (await api(who.impex, 'GET', `/supply-chain/deliveries/${delivery.id}`)).body?.data?.status;
    check(`${label}: ${delivery.number} terminal state is confirmed`, status === 'confirmed', status);
  }
}

// 11 — Finance finalizes both invoices and records the full collections.
async function step11Finance(invoices) {
  const label = '11 · Finance';
  const page = who.finance;
  check(`${label}: one draft invoice per confirmed delivery`, invoices.length === 2, `count=${invoices.length}`);
  for (const invoice of invoices) {
    await open(page, `/accounting/invoices/${invoice.hash}`);
    const finalizePost = page.waitForResponse((r) => r.url().includes(`/invoices/${invoice.hash}/finalize`) && r.request().method() === 'PATCH', { timeout: 20_000 });
    await pageButton(page, 'Finalize');
    await modalButton(page, 'Finalize');
    const finalizeResult = await finalizePost;
    await page.waitForLoadState('networkidle').catch(() => {});
    const number = sql(`select invoice_number from invoices where id=${invoice.dbId}`);
    check(`${label}: ${number} finalized from the invoice page`, finalizeResult.status() === 200, `HTTP ${finalizeResult.status()}`);

    const balance = sql(`select balance from invoices where id=${invoice.dbId}`);
    await pageButton(page, 'Record collection');
    await dialogSelect(page, 0, { label: /1020/ }).catch(() => dialogSelect(page, 0, { index: 1 }));
    await page.getByRole('dialog').getByRole('spinbutton').last().fill(balance);
    await dialogSelect(page, 1, { label: /Bank/i }).catch(() => dialogSelect(page, 1, { index: 1 }));
    await page.getByRole('dialog').getByRole('textbox').last().fill(`UI-${RUN}-${number}`);
    const collectPost = page.waitForResponse((r) => r.url().includes(`/invoices/${invoice.hash}/collections`) && r.request().method() === 'POST', { timeout: 20_000 });
    await modalButton(page, 'Record');
    const collectResult = await collectPost;
    await page.waitForLoadState('networkidle').catch(() => {});
    await expectToast(page, /record|collection/i, `${label}: ${number} collection of ₱${balance} recorded through the modal`);
    check(`${label}: ${number} collection POST accepted`, collectResult.status() === 201 || collectResult.status() === 200, `HTTP ${collectResult.status()}`);
    const after = sql(`select status||':'||balance from invoices where id=${invoice.dbId}`);
    check(`${label}: ${number} fully paid`, /paid:0\.00/.test(after), after);
  }
  const soStatus = sql(`select status from sales_orders where so_number='${SO_NUMBER}'`);
  check(`${label}: the sales order closed once delivered AND paid`, soStatus === 'closed', soStatus);
}

/* ─── DB-level alignment guards ───────────────────────────────────── */

async function verifyAlignment(woDbId) {
  const label = '12 · Alignment';
  const soId = sql(`select id from sales_orders where so_number='${SO_NUMBER}'`);
  const guards = {
    'WO belongs to the SO': sql(`select count(*) from work_orders where id=${woDbId} and sales_order_id=${soId}`) === '1',
    'WO is completed': sql(`select status from work_orders where id=${woDbId}`) === 'completed',
    'every output batch has exactly one PASSED outgoing inspection': sql(`select count(*) from work_order_outputs o where o.work_order_id=${woDbId} and (select count(*) from inspections i where i.work_order_output_id=o.id and i.stage='outgoing' and i.status='passed') <> 1`) === '0',
    'every delivery reached customer-confirmed': sql(`select count(*) from deliveries where sales_order_id=${soId} and status <> 'confirmed'`) === '0',
    'every delivery has a proof of delivery': sql(`select count(*) from deliveries d where d.sales_order_id=${soId} and (select count(*) from delivery_proofs p where p.delivery_id=d.id) = 0`) === '0',
    'every delivered line references a PASSED inspection': sql(`select count(*) from delivery_items di join deliveries d on d.id=di.delivery_id where d.sales_order_id=${soId} and di.inspection_id is not null and (select status from inspections where id=di.inspection_id) <> 'passed'`) === '0',
    'every paid invoice carries a posted journal entry': sql(`select count(*) from invoices i where i.sales_order_id=${soId} and i.status='paid' and (select status from journal_entries where id=i.journal_entry_id) <> 'posted'`) === '0',
    'paid invoice balances are zero': sql(`select count(*) from invoices where sales_order_id=${soId} and status='paid' and balance <> 0`) === '0',
    'every journal entry balances': sql(`select count(*) from (select je.id from journal_entries je join journal_entry_lines l on l.journal_entry_id=je.id group by je.id having sum(l.debit) <> sum(l.credit)) x`) === '0',
    'stock was actually consumed (material issue movements)': Number(sql(`select count(*) from stock_movements where reference_type='material_issue_slip'`)) >= 1,
    'the SO is closed': sql(`select status from sales_orders where id=${soId}`) === 'closed',
    'no orphan work orders anywhere': sql(`select count(*) from work_orders where sales_order_id is null and status <> 'cancelled'`) === '0',
  };
  for (const [name, ok] of Object.entries(guards)) check(`${label}: ${name}`, ok);
}

/* ─── Main ────────────────────────────────────────────────────────── */

(async () => {
  let server;
  try {
    server = await setUp();
    const accounts = {
      sales: 'crm@ogami.test', ppc: 'ppc@ogami.test', prod: 'production@ogami.test',
      qc: 'qc@ogami.test', warehouse: 'warehouse@ogami.test', impex: 'impex@ogami.test',
      driver: 'driver@ogami.test', finance: 'finance@ogami.test', customer: fixture.customer_email,
    };
    for (const [key, email] of Object.entries(accounts)) {
      who[key] = await login(email, key === 'customer' ? '/b2b/customer/me' : '/auth/user');
      await new Promise((r) => setTimeout(r, 1_000)); // ease the per-IP auth limiter
    }
    check('All 9 role contexts signed in through the real sign-in form', Object.keys(who).length === 9, Object.keys(who).join(','));

    await step1SalesOrder();
    const prod = who.prod;

    const woId = (((await api(prod, 'GET', `/production/work-orders?search=${WO_NUMBER}`)).body?.data ?? [])
      .find((w) => w.wo_number === WO_NUMBER) ?? {}).id;
    check('0 · Lookups: work order hash id resolved (read-only)', !!woId, woId);

    await step2Schedule();
    const woDbId = Number(sql(`select id from work_orders where wo_number='${WO_NUMBER}'`) || 0);
    await step3ConfirmAndStart(woId);
    await step4IssueMaterial(woId, woDbId);
    await step5ProduceOutputs(woId);
    const qcNumbers = await step6QC(woDbId);
    await step7CheckerReview(qcNumbers);

    const deliveries = sql(`select delivery_number from deliveries where sales_order_id=(select id from sales_orders where so_number='${SO_NUMBER}') and status='scheduled' order by id`).split('\n').filter(Boolean);
    const deliveryObjs = [];
    const impex = who.impex;
    for (const number of deliveries) {
      const found = ((await api(impex, 'GET', `/supply-chain/deliveries?search=${number}`)).body?.data ?? [])
        .find((d) => d.delivery_number === number);
      check(`7.5 · Lookups: delivery ${number} resolved (read-only)`, !!found, found?.id);
      deliveryObjs.push({ number, id: found.id });
    }
    check('7.5 · Chain gate: deliveries only exist now that QC passed', deliveries.length === 2, deliveries.join(', '));

    await step8Dispatch(deliveryObjs);
    await step9DriverProof(deliveryObjs);
    await step10CustomerConfirm(deliveryObjs);

    const invoices = [];
    const finance = who.finance;
    for (const delivery of deliveryObjs) {
      const detail = (await api(finance, 'GET', `/supply-chain/deliveries/${delivery.id}`)).body?.data;
      const inv = detail?.invoice;
      check(`10.5 · Chain gate: ${delivery.number} auto-drafted its invoice on confirmation`, !!inv && inv.status === 'draft', inv ? `${inv.invoice_number ?? '(number assigned at finalize)'} ${inv.status}` : 'none');
      if (inv) {
        // Draft invoices carry no number yet (assigned at finalize) and the
        // delivery payload omits balance — verify both from the DB, read-only.
        const dbId = Number(sql(`select id from invoices where delivery_id=(select id from deliveries where delivery_number='${delivery.number}') order by id desc limit 1`) || 0);
        const draftStatus = sql(`select status from invoices where id=${dbId}`);
        check(`10.5 · Chain gate: ${delivery.number} invoice is a draft in the DB`, draftStatus === 'draft', draftStatus);
        invoices.push({ hash: inv.id, dbId });
      }
    }

    await step11Finance(invoices);
    await verifyAlignment(woDbId);

    await shot(who.sales, `/crm/sales-orders/${SO_ID}`, 'ui-chain-so-closed');
    await shot(who.finance, `/accounting/invoices/${invoices[0]?.hash}`, 'ui-chain-invoice-paid');
  } catch (error) {
    check('run aborted', false, error.stack?.slice(0, 900) ?? String(error));
  } finally {
    report.summary = {
      passed: report.checks.length, failed: report.failures.length,
      server_errors: report.http_errors.length, page_errors: report.page_errors.length,
    };
    fs.mkdirSync(OUT, { recursive: true });
    fs.writeFileSync(path.join(OUT, 'report.json'), JSON.stringify(report, null, 2));
    console.log(`\n${report.summary.passed} passed, ${report.summary.failed} failed, ${report.summary.server_errors} 5xx, ${report.summary.page_errors} page errors → ${OUT}/report.json`);
    for (const f of report.failures) console.log(`  FAIL ${f.name} — ${f.evidence.slice(0, 220)}`);
    await browser?.close();
    await tearDown(server);
    process.exitCode = report.failures.length || report.http_errors.length || report.page_errors.length ? 1 : 0;
  }
})();
