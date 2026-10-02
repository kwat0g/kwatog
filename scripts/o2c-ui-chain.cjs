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
const DB_USER = process.env.O2CUI_DB_USER || 'ogami';
const BASE = `http://127.0.0.1:${SPA_PORT}`;
const OUT = process.env.O2CUI_OUTPUT || path.join('/tmp', `o2c-ui-chain-${RUN}`);
const KEEP = process.env.O2CUI_KEEP === '1';
const FIXTURE_IN_CONTAINER = `/tmp/o2c-fixture-${RUN}.json`;
if (!/^[a-z_][a-z0-9_]*$/i.test(DB_USER)) throw new Error('O2CUI_DB_USER must be a PostgreSQL role name.');
const ENV = {
  DB_DATABASE: DB, CACHE_STORE: 'array', QUEUE_CONNECTION: 'sync', MAIL_MAILER: 'array',
  BROADCAST_CONNECTION: 'log', SESSION_DRIVER: 'database', APP_URL: BASE,
  SESSION_DOMAIN: '127.0.0.1', SESSION_SECURE_COOKIE: 'false', SESSION_SAME_SITE: 'lax',
  SANCTUM_STATEFUL_DOMAINS: `127.0.0.1:${SPA_PORT},localhost:${SPA_PORT}`,
  COMPANY_LEGAL_NAME: 'Ogami Audit Test Corporation', COMPANY_ADDRESS: 'Audit Test Site',
  COMPANY_PHONE: '+639000000000', COMPANY_EMAIL: 'audit@ogami.test', COMPANY_TIN: '000-000-000-000',
  COMPANY_VAT_STATUS: 'VAT Registered', COMPANY_PUBLIC_URL: 'https://ogami.test',
  COMPANY_SALES_INBOX_EMAIL: 'sales@ogami.test', COMPANY_CERTIFICATION: 'Audit Test Certified',
  COMPANY_EMPLOYEE_EMAIL_DOMAIN: 'ogami.test', COMPANY_LATITUDE: '14.286', COMPANY_LONGITUDE: '120.9345',
  COMPANY_EMAIL_BRAND_NAME: 'Ogami Audit Test',
  O2C_BROWSER_DB: DB, O2C_RUN_ID: RUN, O2C_FIXTURE_PATH: FIXTURE_IN_CONTAINER,
};

const report = { run_id: RUN, database: DB, base_url: BASE, mode: 'ui-driven', checks: [], failures: [], api_errors: [], http_errors: [], console_errors: [], page_errors: [] };
let fixture;

function check(name, ok, evidence = '') {
  const text = typeof evidence === 'string' ? evidence : JSON.stringify(evidence);
  (ok ? report.checks : report.failures).push({ name, evidence: text });
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${text ? ` — ${text.slice(0, 260)}` : ''}`);
  if (!ok) {
    const error = new Error(`Assertion failed: ${name}${text ? ` — ${text}` : ''}`);
    error.auditCheck = true;
    throw error;
  }
  return ok;
}

/* ─── Environment (same harness as o2c-headless.cjs) ──────────────── */

function compose(args) {
  return execFileSync('docker', ['compose', ...args], { cwd: ROOT, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'], maxBuffer: 64 * 1024 * 1024 });
}
const envFlags = () => Object.entries(ENV).flatMap(([k, v]) => ['-e', `${k}=${v}`]);
const artisan = (...args) => compose(['exec', '-T', ...envFlags(), 'api', 'php', '-d', 'memory_limit=1G', 'artisan', ...args]);
const psql = (sqlText, db = 'postgres') => compose(['exec', '-T', 'db', 'psql', '-U', DB_USER, '-d', db, '-At', '-F', '|', '-c', sqlText]).trim();
const sql = (query) => psql(query, DB);
const apiIp = () => {
  const container = compose(['ps', '-q', 'api']).trim().split(/\s+/)[0];
  if (!container) throw new Error('The selected Compose project has no running api service.');
  return execFileSync('docker', ['inspect', '-f', '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}', container], { encoding: 'utf8' }).trim();
};

function stopApiServer() {
  for (const pattern of [`port=${API_PORT}`, `0.0.0.0:${API_PORT}`]) {
    try { compose(['exec', '-T', 'api', 'pkill', '-f', pattern]); } catch { /* not running */ }
  }
}

async function setUp() {
  fs.mkdirSync(OUT, { recursive: true });
  stopApiServer();
  psql(`CREATE DATABASE ${DB} OWNER ${DB_USER};`);
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
  const dirty = report.failures.length > 0 || report.http_errors.length > 0 || unexplainedConsoleErrors().length > 0 || report.page_errors.length > 0;
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

function assertCleanStep(label, expectedApiErrors = []) {
  const missingExpectedErrors = expectedApiErrors.filter((error) => !report.api_errors.includes(error));
  check(`${label}: expected API rejection was recorded`, missingExpectedErrors.length === 0, missingExpectedErrors);
  report.expected_api_errors ??= [];
  report.expected_api_errors.push(...expectedApiErrors);
  const allowedApiErrors = new Set(report.expected_api_errors);
  const unexpectedApiErrors = report.api_errors.filter((error) =>
    !/ 401 GET \/api\/v1\/auth\/user$/.test(error) && !allowedApiErrors.has(error));
  check(`${label}: no unexplained API errors`, unexpectedApiErrors.length === 0, unexpectedApiErrors);
  const consoleErrors = unexplainedConsoleErrors();
  check(`${label}: no unexplained browser console errors`, consoleErrors.length === 0, consoleErrors);
}

function unexplainedConsoleErrors() {
  // Chromium logs expected API rejection responses as console errors. AuthGuard
  // probes `/auth/user` while signed out; forbidden-action probes are listed
  // explicitly in expected_api_errors. All other failures remain visible.
  let knownAuthProbes = report.api_errors.filter((error) => / 401 GET \/api\/v1\/auth\/user$/.test(error)).length;
  const expectedStatuses = new Map();
  for (const error of report.expected_api_errors ?? []) {
    const status = error.match(/\s(\d{3})\s(?:GET|POST|PUT|PATCH|DELETE)\s/)?.[1];
    if (status) expectedStatuses.set(status, (expectedStatuses.get(status) ?? 0) + 1);
  }
  const expected = [];
  const unexpected = [];
  for (const error of report.console_errors) {
    if (/Failed to load resource: the server responded with a status of 401 \(Unauthorized\)$/.test(error) && knownAuthProbes > 0) {
      expected.push(error);
      knownAuthProbes -= 1;
    } else {
      const status = error.match(/Failed to load resource: the server responded with a status of (\d{3}) /)?.[1];
      const remaining = status ? expectedStatuses.get(status) ?? 0 : 0;
      if (remaining > 0) {
        expected.push(error);
        expectedStatuses.set(status, remaining - 1);
      } else {
        unexpected.push(error);
      }
    }
  }
  report.expected_console_errors = expected;
  return unexpected;
}

async function signIn(page, email, sessionCheck) {
  const loginRoute = sessionCheck === '/b2b/customer/me' ? '/portal/customer/login' : '/sign-in';
  // The login form disables its inputs while a 429 cooldown runs; back-to-back
  // runs log in many accounts from one IP, so wait OUT the cooldown instead of
  // timing out on a disabled field. The form itself can also take a moment on
  // a cold Vite transform, so retry the navigation until the fields render.
  const emailInput = page.getByLabel('Email');
  let visible = false;
  for (let i = 0; i < 6 && !visible; i += 1) {
    try {
      await page.goto(loginRoute, { waitUntil: 'domcontentloaded', timeout: 20_000 });
      await page.waitForTimeout(500);
      // A stale session (cached SPA state from an earlier run) bounces /sign-in
      // back to the dashboard. Clear every client-side artifact and hard-reload.
      if (!page.url().includes('/sign-in')) {
        await page.evaluate(() => { localStorage.clear(); sessionStorage.clear(); }).catch(() => {});
        await contextClearCookies(page);
        await page.goto(loginRoute, { waitUntil: 'domcontentloaded', timeout: 20_000 });
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
  await page.waitForFunction(() => !window.location.pathname.startsWith('/sign-in'), undefined, { timeout: 30_000 });
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
  page.on('console', (message) => {
    if (message.type() === 'error') report.console_errors.push(`${email}: ${message.text().slice(0, 500)}`);
  });
  page.on('response', (r) => {
    if (r.status() < 400 || !r.url().includes('/api/')) return;
    const failure = `${email} ${r.status()} ${r.request().method()} ${r.url().replace(BASE, '')}`;
    report.api_errors.push(failure);
    if (r.status() >= 500) report.http_errors.push(failure);
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
  return {
    status: detail?.status,
    mode: detail?.inspection_mode,
    resultAuthors: detail?.result_authors ?? [],
    reviewer: detail?.reviewer,
  };
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
    const routePattern = detailPattern.source;
    await page.waitForFunction((pattern) => new RegExp(pattern).test(window.location.pathname), routePattern, { timeout: 10_000 });
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
    await page.waitForFunction((pattern) => new RegExp(pattern).test(window.location.pathname), detailUrl.source, { timeout: 30_000 });
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
  const totals = sql(`select subtotal||'|'||vat_amount||'|'||total_amount from sales_orders where so_number='${SO_NUMBER}'`);
  check(`${label}: stored SO totals reconcile to 200 × ₱12.50 plus 12% VAT`, totals === '2500.00|300.00|2800.00', totals);
  check(`${label}: order changes are audited by sales`, Number(sql(`select count(*) from audit_logs where model_type like '%SalesOrder' and model_id=(select id from sales_orders where so_number='${SO_NUMBER}') and user_id=(select id from users where email='crm@ogami.test') and action in ('created','updated')`)) >= 2);

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

// A warehouse user can forge a hidden planned work-order ID in the issue API.
// The server must reject that jump before writing a slip or consuming stock.
async function attemptPlannedWorkOrderIssue(woId) {
  const label = '1.6 · Planned work-order API gate';
  const page = who.warehouse;
  const stockBefore = sql(`select coalesce(sum(quantity),0) from stock_levels where item_id=(select id from items where code='RM-${RUN}')`);
  const slipsBefore = sql(`select count(*) from material_issue_slips where work_order_id=(select id from work_orders where wo_number='${WO_NUMBER}')`);
  const response = await api(page, 'POST', '/inventory/material-issues', {
    work_order_id: woId,
    issued_date: today(),
    items: [{ item_id: fixture.resin_item, location_id: fixture.raw_location, quantity_issued: '0.001' }],
  }, {}, { probe: false });
  check(`${label}: API rejects issuing to a planned work order`, response.status === 422 && response.body?.message === 'Materials can only be issued to confirmed, in-progress, or paused work orders.', response);
  const stockAfter = sql(`select coalesce(sum(quantity),0) from stock_levels where item_id=(select id from items where code='RM-${RUN}')`);
  const slipsAfter = sql(`select count(*) from material_issue_slips where work_order_id=(select id from work_orders where wo_number='${WO_NUMBER}')`);
  check(`${label}: rejection leaves stock unchanged`, stockAfter === stockBefore, `${stockBefore} → ${stockAfter}`);
  check(`${label}: rejection creates no issue slip`, slipsAfter === slipsBefore, `${slipsBefore} → ${slipsAfter}`);
  assertCleanStep(label, [`${emailOf(page)} ${response.status} POST /api/v1/inventory/material-issues`]);
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
  const confirmedSchedule = sql(`select count(*) from production_schedules where work_order_id=(select id from work_orders where wo_number='${WO_NUMBER}') and status='confirmed' and is_confirmed=true and confirmed_by=(select id from users where email='production@ogami.test')`);
  check(`${label}: confirmed schedule is persisted under production manager`, confirmedSchedule === '1', confirmedSchedule);
  const ppcConfirmed = sql(`select count(*) from production_schedules where work_order_id=(select id from work_orders where wo_number='${WO_NUMBER}') and confirmed_by=(select id from users where email='ppc@ogami.test')`);
  check(`${label}: PPC proposal did not record an approval`, ppcConfirmed === '0', ppcConfirmed);
}

// 3 — Production manager confirms the WO (machine + mold) and starts it.
async function step3ConfirmAndStart(woId, woDbId) {
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
  const autoIssue = sql(`select coalesce(sum(quantity),0) from stock_movements where reference_type='work_order' and reference_id=${woDbId} and movement_type='material_issue'`);
  check(`${label}: start issued only the 4.000 kg BOM requirement`, autoIssue === '4.000', autoIssue);
  const stock = sql(`select coalesce(sum(quantity),0) from stock_levels where item_id=(select id from items where code='RM-${RUN}')`);
  check(`${label}: on-hand stock reflects the automatic issue`, stock === '496.000', stock);
  check(`${label}: start transition is audited by production`, Number(sql(`select count(*) from audit_logs where model_type like '%WorkOrder' and model_id=${woDbId} and user_id=(select id from users where email='production@ogami.test') and action='updated' and new_values->>'status'='in_progress'`)) === 1);
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
  // Start already issued the 4.000 kg BOM requirement; 0.060 kg covers the
  // three rejects below, for 4.060 kg total.
  await page.locator('input[name="items.0.quantity_issued"]').fill('0.060');
  const issueResponse = page.waitForResponse((r) => r.url().endsWith('/inventory/material-issues') && r.request().method() === 'POST', { timeout: 20_000 });
  await pageButton(page, 'Create issue');
  // The success toast is the first thing shown; read it before it expires.
  await expectToast(page, /created|issue/i, `${label}: material issue recorded through the form (0.060 kg resin)`);
  const issueResult = await issueResponse;
  check(`${label}: the issue POST succeeded`, issueResult.status() === 201 || issueResult.status() === 200, `HTTP ${issueResult.status()}`);
  const issued = sql(`select coalesce(sum(i.quantity_issued),0) from material_issue_slips s join material_issue_slip_items i on i.material_issue_slip_id=s.id where s.work_order_id=${woDbId} and s.status='issued'`);
  check(`${label}: manual issue is 0.060 kg`, issued === '0.060', issued);
  const totalIssued = sql(`select coalesce(sum(quantity),0) from stock_movements where item_id=(select id from items where code='RM-${RUN}') and movement_type='material_issue'`);
  check(`${label}: total issue equals 4.060 kg for 203 gross units`, totalIssued === '4.060', totalIssued);
  const stock = sql(`select coalesce(sum(quantity),0) from stock_levels where item_id=(select id from items where code='RM-${RUN}')`);
  check(`${label}: raw-material stock reconciles to 500 − 4.060`, stock === '495.940', stock);
  check(`${label}: issue audit identifies the warehouse actor`, Number(sql(`select count(*) from audit_logs where model_type like '%MaterialIssueSlip' and model_id=(select id from material_issue_slips where work_order_id=${woDbId}) and user_id=(select id from users where email='warehouse@ogami.test') and action='created'`)) === 1);
}

async function attemptUnauthorizedOutput(woId, woDbId) {
  const label = '4.5 · Warehouse output permission gate';
  const page = who.warehouse;
  const before = sql(`select count(*) from work_order_outputs where work_order_id=${woDbId}`);
  const auditBefore = sql(`select count(*) from audit_logs where model_type like '%WorkOrderOutput' and user_id=(select id from users where email='warehouse@ogami.test') and action='created'`);
  const response = await api(page, 'POST', `/production/work-orders/${woId}/outputs`, {
    good_count: 1, reject_count: 0, shift: 'Day Shift',
  }, {}, { probe: false });
  check(`${label}: warehouse cannot record production output`, response.status === 403, response);
  check(`${label}: forbidden write creates no output`, sql(`select count(*) from work_order_outputs where work_order_id=${woDbId}`) === before, before);
  check(`${label}: forbidden write creates no audit event`, sql(`select count(*) from audit_logs where model_type like '%WorkOrderOutput' and user_id=(select id from users where email='warehouse@ogami.test') and action='created'`) === auditBefore, auditBefore);
  assertCleanStep(label, [`${emailOf(page)} ${response.status} POST /api/v1/production/work-orders/${woId}/outputs`]);
}

// 5 — Production records two output batches through the form, then completes.
async function step5ProduceOutputs(woId) {
  const label = '5 · Production';
  const page = who.prod;
  for (const [good, rejectCount, defectCount] of [[150, 3, 3], [50, 0, 0]]) {
    await open(page, `/production/work-orders/${woId}`);
    await pageButton(page, 'Record output');
    await page.waitForFunction(() => /\/record-output$/.test(window.location.pathname), undefined, { timeout: 15_000 });
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
  const counts = sql(`select sum(good_count)||'|'||sum(reject_count) from work_order_outputs where work_order_id=(select id from work_orders where wo_number='${WO_NUMBER}')`);
  check(`${label}: raw batch counts reconcile to 200 good and 3 rejected`, counts === '200|3', counts);
  const receipt = sql(`select coalesce(sum(quantity),0) from stock_movements where movement_type='production_receipt' and reference_type='work_order_output' and reference_id in (select id from work_order_outputs where work_order_id=(select id from work_orders where wo_number='${WO_NUMBER}'))`);
  check(`${label}: only the 200 good units enter finished-goods stock`, receipt === '200.000', receipt);
  const finishedStock = sql(`select coalesce(sum(quantity),0) from stock_levels where item_id=(select id from items where code='O2C-${RUN}')`);
  check(`${label}: finished-goods on-hand matches production receipts`, finishedStock === '200.000', finishedStock);
  check(`${label}: output audit identifies production actor`, Number(sql(`select count(*) from audit_logs where model_type like '%WorkOrderOutput' and model_id in (select id from work_order_outputs where work_order_id=(select id from work_orders where wo_number='${WO_NUMBER}')) and user_id=(select id from users where email='production@ogami.test') and action='created'`)) === 2);
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
    // Section 2: tap targets replace the input when the reject limit is small.
    const defectGroup = page.getByRole('group', { name: 'Defective pieces found' });
    if (await defectGroup.count()) {
      const choices = defectGroup.getByRole('button');
      await choices.nth(good ? 0 : (await choices.count()) - 1).click();
    } else {
      await page.getByLabel('Defective pieces found').fill(good ? '0' : '8');
    }
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
  const detail = await inspectionStatus(page, hashId);
  const actor = (await api(page, 'GET', '/auth/user')).body?.data;
  check(`6 · QC: ${inspectionNumber} records the actual measurement author`, detail.resultAuthors.some((author) => author.id === actor?.id), detail.resultAuthors);
  const authorLabel = page.getByText('Results recorded by', { exact: true });
  const authorText = await authorLabel.locator('xpath=..').innerText().catch(() => '');
  check(`6 · QC: ${inspectionNumber} shows the result author in its detail page`, authorText.includes(actor?.name ?? '__missing__'), authorText);
  return detail.status;
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

async function attemptQcSelfReview(numbers) {
  const page = who.qc;
  const inspection = ((await api(page, 'GET', `/quality/inspections?search=${numbers[0]}`)).body?.data ?? [])
    .find((row) => row.inspection_number === numbers[0]);
  if (!inspection) throw new Error(`Cannot find ${numbers[0]} for the self-review gate.`);
  await open(page, `/quality/inspections/${inspection.id}`);
  const buttons = await page.getByRole('button', { name: 'Approve pass' }).count();
  check('6.5 · QC maker-checker gate: the result author has no approve control', buttons === 0, `Approve pass buttons=${buttons}`);
  const dbId = Number(sql(`select id from inspections where inspection_number='${numbers[0]}'`));
  const auditBefore = sql(`select count(*) from audit_logs where model_type like '%Inspection' and model_id=${dbId} and user_id=(select id from users where email='qc@ogami.test') and action='updated'`);
  const response = await api(page, 'PATCH', `/quality/inspections/${inspection.id}/review`, { decision: 'passed' }, {}, { probe: false });
  check('6.5 · QC maker-checker gate: direct self-review is rejected', response.status === 403, response);
  check('6.5 · QC maker-checker gate: inspection remains awaiting review', sql(`select status from inspections where id=${dbId}`) === 'awaiting_review');
  check('6.5 · QC maker-checker gate: self-review writes no audit record', sql(`select count(*) from audit_logs where model_type like '%Inspection' and model_id=${dbId} and user_id=(select id from users where email='qc@ogami.test') and action='updated'`) === auditBefore, auditBefore);
  assertCleanStep('6.5 · QC maker-checker gate', [`${emailOf(page)} ${response.status} PATCH /api/v1/quality/inspections/${inspection.id}/review`]);
}

// 7 — Production manager (checker) approves both results.
async function step7CheckerReview(numbers) {
  const label = '7 · Checker';
  const page = who.prod;
  for (const [index, number] of numbers.entries()) {
    await openDetail(page, `/quality/inspections?search=${number}`, number, /\/quality\/inspections\/[^/]+$/, null);
    await pageButton(page, 'Approve pass');
    await modalButton(page, 'Approve pass');
    await expectToast(page, /approved/i, `${label}: ${number} approved through the maker-checker dialog`);
    const hashId = page.url().split('/').pop();
    const { status } = await inspectionStatus(page, hashId);
    check(`${label}: ${number} terminal state is passed`, status === 'passed', status);
    const dbId = Number(sql(`select id from inspections where inspection_number='${number}'`));
    const checker = sql(`select count(*) from inspections where id=${dbId} and reviewed_by=(select id from users where email='production@ogami.test') and reviewed_at is not null`);
    check(`${label}: ${number} review records the production checker`, checker === '1', checker);
    const deliveries = sql(`select count(*) from deliveries where sales_order_id=(select id from sales_orders where so_number='${SO_NUMBER}')`);
    const expectedDeliveries = String(index + 1);
    check(`${label}: one partial delivery exists for each passed outgoing lot`, deliveries === expectedDeliveries, `${deliveries} deliveries (expected ${expectedDeliveries})`);
    const linkedDelivery = sql(`select count(*) from deliveries d join delivery_items di on di.delivery_id=d.id join inspections i on i.id=di.inspection_id where i.inspection_number='${number}' and d.status='scheduled'`);
    check(`${label}: ${number} authorizes exactly one scheduled delivery line`, linkedDelivery === '1', linkedDelivery);
    if (index === 0 && numbers.length > 1) await attemptDeliveryFromUnreviewedLot(numbers[1]);
  }
}

async function attemptDeliveryFromUnreviewedLot(inspectionNumber) {
  const label = '7.5 · Pending-QC delivery gate';
  const page = who.impex;
  const inspection = ((await api(who.qc, 'GET', `/quality/inspections?search=${inspectionNumber}`)).body?.data ?? [])
    .find((row) => row.inspection_number === inspectionNumber);
  const order = (await api(who.sales, 'GET', `/crm/sales-orders/${SO_ID}`)).body?.data;
  const item = order?.items?.[0];
  if (!inspection || !item) throw new Error(`Cannot construct the pending-QC delivery attempt for ${inspectionNumber}.`);
  const before = {
    deliveries: sql(`select count(*) from deliveries where sales_order_id=(select id from sales_orders where so_number='${SO_NUMBER}')`),
    reservations: sql(`select count(*) from delivery_stock_reservations r join deliveries d on d.id=r.delivery_id where d.sales_order_id=(select id from sales_orders where so_number='${SO_NUMBER}')`),
    movements: sql("select count(*) from stock_movements where reference_type='delivery'"),
  };
  const response = await api(page, 'POST', '/supply-chain/deliveries', {
    sales_order_id: SO_ID,
    scheduled_date: today(),
    notes: `Audit pending-QC gate ${RUN}`,
    items: [{ sales_order_item_id: item.id, quantity: '0.01', inspection_id: inspection.id }],
  }, { 'Idempotency-Key': `audit-${RUN}-pending-qc` }, { probe: false });
  check(`${label}: server rejects an unreviewed outgoing lot`, response.status === 422 && response.body?.message === 'The selected inspection is not a passed outgoing inspection.', response);
  check(`${label}: rejected attempt creates no delivery`, sql(`select count(*) from deliveries where sales_order_id=(select id from sales_orders where so_number='${SO_NUMBER}')`) === before.deliveries, before.deliveries);
  check(`${label}: rejected attempt reserves no stock`, sql(`select count(*) from delivery_stock_reservations r join deliveries d on d.id=r.delivery_id where d.sales_order_id=(select id from sales_orders where so_number='${SO_NUMBER}')`) === before.reservations, before.reservations);
  check(`${label}: rejected attempt creates no movement`, sql("select count(*) from stock_movements where reference_type='delivery'") === before.movements, before.movements);
  assertCleanStep(label, [`${emailOf(page)} ${response.status} POST /api/v1/supply-chain/deliveries`]);
}

function finishedGoodStockSnapshot() {
  return sql(`select coalesce(string_agg(location_id::text||':'||quantity::numeric(15,3)::text||':'||reserved_quantity::numeric(15,3)::text, ',' order by location_id),'') from stock_levels where item_id=(select id from items where code='O2C-${RUN}')`);
}

async function reserveShipmentStock(page, delivery, label) {
  await open(page, `/supply-chain/deliveries/${delivery.id}`);
  const current = (await api(page, 'GET', `/supply-chain/deliveries/${delivery.id}`)).body?.data;
  if (current?.stock_reservation_status === 'reserved') {
    check(`${label}: ${delivery.number} already has its inspected lot reserved`, current.can_reserve_stock === false);
    return false;
  }
  let reserveResult = null;
  for (let attempt = 0; attempt < 3 && !reserveResult; attempt += 1) {
    try {
      const reserveBtn = page.getByRole('button', { name: 'Reserve shipment stock' });
      if ((await reserveBtn.count()) === 0) break;
      const reservePost = page.waitForResponse((r) => r.url().includes(`/supply-chain/deliveries/${delivery.id}/reserve-stock`) && r.request().method() === 'POST', { timeout: 20_000 });
      await reserveBtn.first().click();
      reserveResult = await reservePost;
    } catch {
      await open(page, `/supply-chain/deliveries/${delivery.id}`);
    }
  }
  check(`${label}: ${delivery.number} inspected lot reserved from the page`, !!reserveResult && [200, 201].includes(reserveResult.status()), reserveResult ? `HTTP ${reserveResult.status()}` : 'no reservation POST observed');
  await open(page, `/supply-chain/deliveries/${delivery.id}`);
  return true;
}

// 8 — ImpEx assigns van + driver, then walks the delivery to delivered.
async function step8Dispatch(deliveries) {
  const label = '8 · ImpEx';
  const page = who.impex;
  for (let deliveryIndex = 0; deliveryIndex < deliveries.length; deliveryIndex += 1) {
    let delivery = deliveries[deliveryIndex];
    await openDetail(page, `/supply-chain/deliveries?search=${delivery.number}`, delivery.number, /\/supply-chain\/deliveries\/[^/]+$/, `/supply-chain/deliveries/${delivery.id}`);
    await page.getByRole('button', { name: 'Assign driver & vehicle', exact: true }).waitFor({ state: 'visible', timeout: 15_000 });
    const cancelActionCount = await page.getByRole('button', { name: 'Cancel delivery', exact: true }).count();
    check(`${label}: ${delivery.number} scheduled delivery exposes its cancel action`, cancelActionCount === 1, `count=${cancelActionCount}`);

    if (deliveryIndex === 0) {
      const deliveryDbId = Number(sql(`select id from deliveries where delivery_number='${delivery.number}'`));
      const source = (await api(page, 'GET', `/supply-chain/deliveries/${delivery.id}`)).body?.data;
      check(`${label}: ${delivery.number} cancellation test has source line and inspection`, !!source?.sales_order?.id && !!source?.items?.[0]?.sales_order_item_id && !!source?.items?.[0]?.inspection?.id, source?.items?.[0]);
      const stockBeforeDeniedRequest = finishedGoodStockSnapshot();
      const stateBeforeDeniedRequest = sql(`select status||'|'||(select count(*) from delivery_stock_reservation_batches where delivery_id=${deliveryDbId})||'|'||(select count(*) from invoices where delivery_id=${deliveryDbId})||'|'||(select count(*) from audit_logs where model_type like '%Delivery' and model_id=${deliveryDbId}) from deliveries where id=${deliveryDbId}`);
      const deniedCancel = await api(who.qc, 'PATCH', `/supply-chain/deliveries/${delivery.id}/status`, { status: 'cancelled' });
      const deniedCancelError = `${emailOf(who.qc)} ${deniedCancel.status} PATCH /api/v1/supply-chain/deliveries/${delivery.id}/status`;
      if (deniedCancel.status === 403) {
        report.expected_api_errors ??= [];
        report.expected_api_errors.push(deniedCancelError);
      }
      const stateAfterDeniedRequest = sql(`select status||'|'||(select count(*) from delivery_stock_reservation_batches where delivery_id=${deliveryDbId})||'|'||(select count(*) from invoices where delivery_id=${deliveryDbId})||'|'||(select count(*) from audit_logs where model_type like '%Delivery' and model_id=${deliveryDbId}) from deliveries where id=${deliveryDbId}`);
      check(`${label}: QC cannot cancel through the API and rejection leaves delivery, reservation, invoice, audit, and stock unchanged`, deniedCancel.status === 403 && stateAfterDeniedRequest === stateBeforeDeniedRequest && finishedGoodStockSnapshot() === stockBeforeDeniedRequest, `${deniedCancel.status}|${stateBeforeDeniedRequest} → ${stateAfterDeniedRequest}`);

      const stockBeforeReserve = finishedGoodStockSnapshot();
      await reserveShipmentStock(page, delivery, label);
      const reserved = sql(`select (select status from delivery_stock_reservation_batches where delivery_id=${deliveryDbId})||'|'||(select count(*) from delivery_stock_reservations where delivery_id=${deliveryDbId} and status='reserved' and consumed_quantity=0 and released_quantity=0)`);
      check(`${label}: ${delivery.number} cancellation setup holds inspected stock`, /^reserved\|[1-9]\d*$/.test(reserved), reserved);
      const stockWhileReserved = finishedGoodStockSnapshot();
      check(`${label}: ${delivery.number} reservation changes holds but not on-hand quantity`, stockBeforeReserve.split(',').map((row) => row.split(':').slice(0, 2).join(':')).join(',') === stockWhileReserved.split(',').map((row) => row.split(':').slice(0, 2).join(':')).join(',') && stockBeforeReserve !== stockWhileReserved, `${stockBeforeReserve} → ${stockWhileReserved}`);

      const cancelResponse = page.waitForResponse((r) => r.url().includes(`/supply-chain/deliveries/${delivery.id}/status`) && r.request().method() === 'PATCH', { timeout: 20_000 });
      await pageButton(page, 'Cancel delivery');
      const cancelDialog = page.getByRole('dialog');
      await cancelDialog.getByText(`Delivery ${delivery.number} cannot be dispatched after cancellation. Any reserved stock will be released; create a new delivery if these items still need to ship.`, { exact: true }).waitFor({ state: 'visible' });
      await modalButton(page, 'Cancel delivery');
      const cancelResult = await cancelResponse;
      check(`${label}: ${delivery.number} cancellation succeeds only after confirmation`, cancelResult.status() === 200, `HTTP ${cancelResult.status()}`);
      await expectToast(page, /Delivery cancelled/i, `${label}: ${delivery.number} cancellation success toast follows server response`);
      await open(page, `/supply-chain/deliveries/${delivery.id}`);
      const cancelled = (await api(page, 'GET', `/supply-chain/deliveries/${delivery.id}`)).body?.data;
      check(`${label}: ${delivery.number} is terminal cancelled and no longer cancellable`, cancelled?.status === 'cancelled' && cancelled?.can_cancel === false && await page.getByRole('button', { name: 'Cancel delivery', exact: true }).count() === 0, `${cancelled?.status}|can_cancel=${cancelled?.can_cancel}`);
      const released = sql(`select (select status from delivery_stock_reservation_batches where delivery_id=${deliveryDbId})||'|'||(select coalesce(sum(quantity),0)::numeric(15,3)::text from delivery_stock_reservations where delivery_id=${deliveryDbId})||'|'||(select coalesce(sum(consumed_quantity),0)::numeric(15,3)::text from delivery_stock_reservations where delivery_id=${deliveryDbId})||'|'||(select coalesce(sum(released_quantity),0)::numeric(15,3)::text from delivery_stock_reservations where delivery_id=${deliveryDbId})||'|'||(select count(*) from delivery_stock_reservations where delivery_id=${deliveryDbId} and status='released')`);
      const [, reservedQty, consumedQty, releasedQty, releasedRows] = released.split('|');
      check(`${label}: ${delivery.number} cancellation releases each stock hold exactly once`, released.startsWith('released|') && reservedQty === releasedQty && consumedQty === '0.000' && Number(releasedRows) > 0, released);
      const cancelledEffects = sql(`select status||'|'||(departed_at is not null)::text||'|'||(delivered_at is not null)::text||'|'||(select count(*) from invoices where delivery_id=${deliveryDbId})||'|'||(select count(*) from delivery_items where delivery_id=${deliveryDbId} and (stock_movement_id is not null or customer_received_quantity is not null)) from deliveries where id=${deliveryDbId}`);
      check(`${label}: ${delivery.number} cancellation creates no invoice, dispatch, movement, or received quantity`, cancelledEffects === 'cancelled|false|false|0|0', cancelledEffects);
      check(`${label}: ${delivery.number} cancellation restores reserved stock and leaves on-hand unchanged`, finishedGoodStockSnapshot() === stockBeforeReserve, `${stockBeforeReserve} → ${finishedGoodStockSnapshot()}`);
      check(`${label}: ${delivery.number} cancellation audit records ImpEx and scheduled → cancelled`, sql(`select count(*) from audit_logs where model_type like '%Delivery' and model_id=${deliveryDbId} and user_id=(select id from users where email='impex@ogami.test') and action='updated' and old_values->>'status'='scheduled' and new_values->>'status'='cancelled'`) === '1');

      await open(page, '/supply-chain/deliveries/create');
      await page.locator('select[name="sales_order_id"]').selectOption(source.sales_order.id);
      await page.locator('select[name="items.0.sales_order_item_id"]').selectOption(source.items[0].sales_order_item_id);
      await page.locator('select[name="items.0.inspection_id"]').selectOption(source.items[0].inspection.id);
      await page.locator('input[name="items.0.quantity"]').fill(String(source.items[0].quantity));
      await page.locator('input[name="scheduled_date"]').fill(today());
      const createResponse = page.waitForResponse((r) => r.url().endsWith('/api/v1/supply-chain/deliveries') && r.request().method() === 'POST', { timeout: 20_000 });
      await pageButton(page, 'Create delivery');
      const createResult = await createResponse;
      check(`${label}: replacement delivery is created through the real form`, createResult.status() === 201, `HTTP ${createResult.status()}`);
      await page.waitForFunction(() => /^\/supply-chain\/deliveries\/[^/]+$/.test(window.location.pathname), undefined, { timeout: 20_000 });
      await expectToast(page, /Delivery created/i, `${label}: replacement creation success toast follows server response`);
      delivery = { id: page.url().split('/').pop(), number: '' };
      const replacement = (await api(page, 'GET', `/supply-chain/deliveries/${delivery.id}`)).body?.data;
      delivery.number = replacement?.delivery_number;
      check(`${label}: replacement reuses released SO line, inspected lot, and quantity`, replacement?.status === 'scheduled' && replacement?.sales_order?.id === source.sales_order.id && replacement?.items?.length === 1 && replacement.items[0].sales_order_item_id === source.items[0].sales_order_item_id && replacement.items[0].inspection?.id === source.items[0].inspection.id && Number(replacement.items[0].quantity) === Number(source.items[0].quantity), replacement);
      const replacementDbId = Number(sql(`select id from deliveries where delivery_number='${delivery.number}'`));
      check(`${label}: replacement is audited as a real ImpEx-created delivery`, sql(`select count(*) from audit_logs where model_type like '%Delivery' and model_id=${replacementDbId} and user_id=(select id from users where email='impex@ogami.test') and action='created'`) === '1');
      check(`${label}: replacement exposes its own cancellation action`, await page.getByRole('button', { name: 'Cancel delivery', exact: true }).count() === 1);
      deliveries[deliveryIndex] = delivery;
    }

    await pageButton(page, 'Assign driver & vehicle');
    await dialogSelect(page, 0, { label: /O2C van/ }).catch(() => dialogSelect(page, 0, { index: 1 }));
    await dialogSelect(page, 1, { index: 1 });
    await page.getByRole('dialog').locator('textarea').first().fill('UI chain run — first available van and driver.');
    await modalButton(page, 'Save assignment');
    await expectToast(page, /assign|saved/i, `${label}: ${delivery.number} van + driver assigned through the dialog`);
    const deliveryDbId = Number(sql(`select id from deliveries where delivery_number='${delivery.number}'`));
    const assignment = sql(`select status||'|'||coalesce(vehicle_id::text,'')||'|'||coalesce(driver_id::text,'') from deliveries where id=${deliveryDbId}`);
    check(`${label}: ${delivery.number} assignment persisted while still scheduled`, /^scheduled\|\d+\|\d+$/.test(assignment), assignment);
    check(`${label}: ${delivery.number} assignment is audited by ImpEx`, Number(sql(`select count(*) from audit_logs where model_type like '%Delivery' and model_id=${deliveryDbId} and user_id=(select id from users where email='impex@ogami.test') and action='updated'`)) >= 1);
    await page.waitForTimeout(400);

    // Departure re-checks a physical reservation of the inspected lot before loading.
    const reservedThroughAction = await reserveShipmentStock(page, delivery, label);

    const reservationKey = sql(`select request_key from delivery_stock_reservation_batches where delivery_id=${deliveryDbId}`);
    const reservation = sql(`select status||'|'||reserved_by::text from delivery_stock_reservation_batches where delivery_id=${deliveryDbId}`);
    const reservationTotals = sql(`select (select coalesce(sum(quantity),0)::numeric(15,3) from delivery_stock_reservations where delivery_id=${deliveryDbId})||'|'||(select coalesce(sum(quantity),0)::numeric(15,3) from delivery_items where delivery_id=${deliveryDbId})`);
    const reservationRows = sql(`select count(*) from delivery_stock_reservations where delivery_id=${deliveryDbId} and status='reserved' and consumed_quantity=0 and released_quantity=0`);
    check(`${label}: ${delivery.number} reservation is complete and owned by ImpEx`, reservation === `reserved|${sql("select id from users where email='impex@ogami.test'")}`, reservation);
    check(`${label}: ${delivery.number} reservation quantity matches every delivery line`, reservationTotals.split('|')[0] === reservationTotals.split('|')[1] && reservationRows !== '0', `${reservationTotals}; rows=${reservationRows}`);
    const batchCountBeforeReplay = sql(`select count(*) from delivery_stock_reservation_batches where delivery_id=${deliveryDbId}`);
    const reservationCountBeforeReplay = sql(`select count(*) from delivery_stock_reservations where delivery_id=${deliveryDbId}`);
    const replayStateBefore = `${batchCountBeforeReplay}|${reservationCountBeforeReplay}|${finishedGoodStockSnapshot()}|${sql(`select count(*) from audit_logs where model_type like '%Delivery' and model_id=${deliveryDbId}`)}`;
    const replay = await api(page, 'POST', `/supply-chain/deliveries/${delivery.id}/reserve-stock`, { request_key: reservationKey }, {}, { probe: false });
    const replayStateAfter = `${sql(`select count(*) from delivery_stock_reservation_batches where delivery_id=${deliveryDbId}`)}|${sql(`select count(*) from delivery_stock_reservations where delivery_id=${deliveryDbId}`)}|${finishedGoodStockSnapshot()}|${sql(`select count(*) from audit_logs where model_type like '%Delivery' and model_id=${deliveryDbId}`)}`;
    if (reservedThroughAction) {
      check(`${label}: retry with the same reservation-endpoint key is idempotent`, replay.status === 200, `HTTP ${replay.status}`);
      check(`${label}: reservation retry creates no duplicate batch, hold, stock change, or audit`, replayStateAfter === replayStateBefore, `${replayStateBefore} → ${replayStateAfter}`);
    } else {
      const expectedReplayError = `${emailOf(page)} 422 POST /api/v1/supply-chain/deliveries/${delivery.id}/reserve-stock`;
      if (replay.status === 422) {
        report.expected_api_errors ??= [];
        report.expected_api_errors.push(expectedReplayError);
      }
      check(`${label}: create-time reservation key cannot be replayed through recovery endpoint and writes nothing`, replay.status === 422 && replay.body?.message === 'This reservation request key was already used for a different delivery or payload.' && replayStateAfter === replayStateBefore, `${replay.status}|${replay.body?.message}|${replayStateBefore} → ${replayStateAfter}`);
    }

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

      const apiStatus = (await api(page, 'GET', `/supply-chain/deliveries/${delivery.id}`)).body?.data?.status;
      const stored = sql(`select status from deliveries where id=${deliveryDbId}`);
      check(`${label}: ${delivery.number} ${next} persisted before the next transition`, apiStatus === next && stored === next, `${apiStatus}|${stored}`);
      check(`${label}: ${delivery.number} ${next} transition has an ImpEx audit entry`, Number(sql(`select count(*) from audit_logs where model_type like '%Delivery' and model_id=${deliveryDbId} and user_id=(select id from users where email='impex@ogami.test') and action='updated' and new_values->>'status'='${next}'`)) >= 1);

      if (next === 'loading') {
        check(`${label}: ${delivery.number} loading reserves its assigned vehicle`, sql(`select v.status from deliveries d join vehicles v on v.id=d.vehicle_id where d.id=${deliveryDbId}`) === 'in_use');
      } else if (next === 'in_transit') {
        const consumed = sql(`select b.status||'|'||(select count(*) from delivery_stock_reservations r where r.delivery_id=${deliveryDbId} and r.status='consumed' and r.consumed_quantity=r.quantity and r.stock_movement_id is not null) from delivery_stock_reservation_batches b where b.delivery_id=${deliveryDbId}`);
        check(`${label}: ${delivery.number} departure consumes each held lot exactly once`, consumed === `consumed|${reservationRows}`, consumed);
      } else {
        check(`${label}: ${delivery.number} delivered records time and received quantities`, sql(`select (delivered_at is not null)::text||'|'||(select count(*) from delivery_items where delivery_id=${deliveryDbId} and customer_received_quantity=quantity) from deliveries where id=${deliveryDbId}`) === 'true|1');
        check(`${label}: ${delivery.number} delivery releases its vehicle`, sql(`select v.status from deliveries d join vehicles v on v.id=d.vehicle_id where d.id=${deliveryDbId}`) === 'available');
      }
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
    await page.waitForFunction(() => /\/driver\/[^/]+\/photo$/.test(window.location.pathname), undefined, { timeout: 15_000 });
    const inputs = page.locator('input[type="file"]');
    await inputs.nth(1).setInputFiles({ name: `receipt-${delivery.number}.png`, mimeType: 'image/png', buffer: png });
    await page.getByRole('button', { name: /Upload photo/i }).click();
    await expectToast(page, /upload|success/i, `${label}: receipt photo uploaded for ${delivery.number} through the driver flow`);
    const deliveryDbId = Number(sql(`select id from deliveries where delivery_number='${delivery.number}'`));
    check(`${label}: ${delivery.number} proof is stored under the driver actor`, Number(sql(`select count(*) from delivery_proofs where delivery_id=${deliveryDbId} and uploaded_by=(select id from users where email='driver@ogami.test') and mime_type='image/png' and file_size>0`)) === 1);
    check(`${label}: uploading proof leaves the delivered state unchanged`, sql(`select status from deliveries where id=${deliveryDbId}`) === 'delivered');
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
    const deliveryDbId = Number(sql(`select id from deliveries where delivery_number='${delivery.number}'`));
    const state = sql(`select status||'|'||coalesce(receiver_name,'')||'|'||coalesce(receiver_position,'')||'|'||(received_at is not null)::text||'|'||(confirmed_at is not null)::text||'|'||coalesce(invoice_handoff_status,'') from deliveries where id=${deliveryDbId}`);
    check(`${label}: ${delivery.number} receiver, confirmation, and invoice handoff are persisted`, /^confirmed\|Juan Dela Cruz\|Warehouse Staff\|true\|true\|generated$/.test(state), state);
    check(`${label}: ${delivery.number} customer confirmation has a portal audit entry`, Number(sql(`select count(*) from audit_logs where actor_type='customer_portal' and action='customer.delivery.confirmed' and model_type like '%Delivery' and model_id=${deliveryDbId} and new_values->>'email'='${fixture.customer_email.replaceAll("'", "''")}'`)) === 1);
    check(`${label}: ${delivery.number} has exactly one linked draft invoice`, sql(`select count(*) from invoices where delivery_id=${deliveryDbId} and status='draft'`) === '1');
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
    const finalized = sql(`select i.status||'|'||j.status||'|'||(j.total_debit=j.total_credit)::text from invoices i join journal_entries j on j.id=i.journal_entry_id where i.id=${invoice.dbId}`);
    check(`${label}: ${number} finalization posts one balanced journal entry`, finalized === 'finalized|posted|true', finalized);

    const balance = sql(`select balance from invoices where id=${invoice.dbId}`);
    const collectionsBefore = sql(`select count(*) from collections where invoice_id=${invoice.dbId}`);
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
    check(`${label}: ${number} collection was recorded once by Finance`, sql(`select count(*) from collections where invoice_id=${invoice.dbId} and amount=${balance} and created_by=(select id from users where email='finance@ogami.test')`) === '1' && collectionsBefore === '0');
    check(`${label}: ${number} collection has one balanced posted journal entry`, sql(`select count(*) from collections c join journal_entries j on j.id=c.journal_entry_id where c.invoice_id=${invoice.dbId} and j.status='posted' and j.total_debit=j.total_credit`) === '1');
  }
  const soId = sql(`select id from sales_orders where so_number='${SO_NUMBER}'`);
  const soStatus = sql(`select status from sales_orders where id=${soId}`);
  check(`${label}: the sales order closed once delivered AND paid`, soStatus === 'closed', soStatus);
  const closeAudit = sql(`select count(*) filter (where new_values::jsonb->>'status'='closed')||'|'||count(*) filter (where new_values::jsonb->>'status'='closed' and user_id=(select id from users where email='finance@ogami.test')) from audit_logs where model_type like '%SalesOrder' and model_id=${soId} and action='updated'`);
  check(`${label}: paid completion records one closed-state audit by Finance`, closeAudit === '1|1', closeAudit);
}

/* ─── DB-level alignment guards ───────────────────────────────────── */

async function verifyAlignment(woDbId) {
  const label = '12 · Alignment';
  const soId = sql(`select id from sales_orders where so_number='${SO_NUMBER}'`);
  const guards = {
    'WO belongs to the SO': sql(`select count(*) from work_orders where id=${woDbId} and sales_order_id=${soId}`) === '1',
    'WO is completed': sql(`select status from work_orders where id=${woDbId}`) === 'completed',
    'every output batch has exactly one PASSED outgoing inspection': sql(`select count(*) from work_order_outputs o where o.work_order_id=${woDbId} and (select count(*) from inspections i where i.work_order_output_id=o.id and i.stage='outgoing' and i.status='passed') <> 1`) === '0',
    'every non-cancelled delivery reached customer-confirmed': sql(`select count(*) from deliveries where sales_order_id=${soId} and status not in ('confirmed','cancelled')`) === '0',
    'cancelled delivery remains terminal with released stock and no invoice or dispatch': sql(`select count(*) from deliveries d where d.sales_order_id=${soId} and d.status='cancelled' and exists(select 1 from delivery_stock_reservation_batches b where b.delivery_id=d.id and b.status='released') and not exists(select 1 from invoices i where i.delivery_id=d.id) and not exists(select 1 from delivery_items di where di.delivery_id=d.id and (di.stock_movement_id is not null or di.customer_received_quantity is not null))`) === '1',
    'every non-cancelled delivery has proof of delivery': sql(`select count(*) from deliveries d where d.sales_order_id=${soId} and d.status <> 'cancelled' and (select count(*) from delivery_proofs p where p.delivery_id=d.id) = 0`) === '0',
    'every delivered line references a PASSED inspection': sql(`select count(*) from delivery_items di join deliveries d on d.id=di.delivery_id where d.sales_order_id=${soId} and di.inspection_id is not null and (select status from inspections where id=di.inspection_id) <> 'passed'`) === '0',
    'every delivered line carries an inspection reference (no vacuous NULLs)': sql(`select count(*) from delivery_items di join deliveries d on d.id=di.delivery_id where d.sales_order_id=${soId} and di.inspection_id is null`) === '0',
    'every paid invoice carries a posted journal entry': sql(`select count(*) from invoices i where i.sales_order_id=${soId} and i.status='paid' and (select status from journal_entries where id=i.journal_entry_id) <> 'posted'`) === '0',
    'paid invoice balances are zero': sql(`select count(*) from invoices where sales_order_id=${soId} and status='paid' and balance <> 0`) === '0',
    'every journal entry balances': sql(`select count(*) from (select je.id from journal_entries je join journal_entry_lines l on l.journal_entry_id=je.id group by je.id having sum(l.debit) <> sum(l.credit)) x`) === '0',
    'stock was actually consumed (material issue movements)': Number(sql(`select count(*) from stock_movements where reference_type='material_issue_slip'`)) >= 1,
    'the SO is closed': sql(`select status from sales_orders where id=${soId}`) === 'closed',
    'no orphan work orders anywhere': sql(`select count(*) from work_orders where sales_order_id is null and status <> 'cancelled'`) === '0',
  };
  for (const [name, ok] of Object.entries(guards)) check(`${label}: ${name}`, ok);
}

async function verifySalesOrderActivity(page) {
  const detail = (await api(page, 'GET', `/crm/sales-orders/${SO_ID}`)).body?.data;
  const history = Array.isArray(detail?.status_history) ? detail.status_history : [];
  const panel = page.getByRole('heading', { name: 'Activity', exact: true }).locator('xpath=../../..');
  const events = await panel.getByRole('listitem').allTextContents();

  check('12 · Activity: UI count matches the sales-order audit history', events.length === history.length, `${events.length}|${history.length}`);
  check('12 · Activity: every recorded status, actor, and comment is visible', history.every((event, index) => {
    const text = events[index] ?? '';
    return text.includes(event.status_label)
      && (!event.actor_name || text.includes(event.actor_name))
      && (!event.comment || text.includes(event.comment));
  }), events);
  check('12 · Activity: timeline has no fabricated current-status row', !events.some((event) => /^Status:\s*/.test(event)), events);
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
    assertCleanStep('0 · Sign in');

    await step1SalesOrder();
    assertCleanStep('1 · Sales order');
    const prod = who.prod;

    const woId = (((await api(prod, 'GET', `/production/work-orders?search=${WO_NUMBER}`)).body?.data ?? [])
      .find((w) => w.wo_number === WO_NUMBER) ?? {}).id;
    check('0 · Lookups: work order hash id resolved (read-only)', !!woId, woId);
    assertCleanStep('1.5 · Work order lookup');
    await attemptPlannedWorkOrderIssue(woId);

    await step2Schedule();
    assertCleanStep('2 · Schedule approval');
    const woDbId = Number(sql(`select id from work_orders where wo_number='${WO_NUMBER}'`) || 0);
    await step3ConfirmAndStart(woId, woDbId);
    assertCleanStep('3 · Confirm and start work order');
    await step4IssueMaterial(woId, woDbId);
    assertCleanStep('4 · Issue material');
    await attemptUnauthorizedOutput(woId, woDbId);
    await step5ProduceOutputs(woId);
    assertCleanStep('5 · Production output');
    const qcNumbers = await step6QC(woDbId);
    assertCleanStep('6 · Quality inspection');
    await attemptQcSelfReview(qcNumbers);
    await step7CheckerReview(qcNumbers);
    assertCleanStep('7 · Quality approval');

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
    assertCleanStep('7.5 · Delivery lookup');

    await step8Dispatch(deliveryObjs);
    assertCleanStep('8 · Dispatch');
    await step9DriverProof(deliveryObjs);
    assertCleanStep('9 · Driver proof');
    await step10CustomerConfirm(deliveryObjs);
    assertCleanStep('10 · Customer receipt');

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
    assertCleanStep('10.5 · Invoice handoff');

    await step11Finance(invoices);
    assertCleanStep('11 · Finance');
    await verifyAlignment(woDbId);

    await shot(who.sales, `/crm/sales-orders/${SO_ID}`, 'ui-chain-so-closed');
    await verifySalesOrderActivity(who.sales);
    await shot(who.finance, `/accounting/invoices/${invoices[0]?.hash}`, 'ui-chain-invoice-paid');
    assertCleanStep('12 · Final inspection');
  } catch (error) {
    if (!error.auditCheck) {
      const evidence = error.stack?.slice(0, 900) ?? String(error);
      report.failures.push({ name: 'run aborted', evidence });
      console.log(`FAIL  run aborted — ${evidence.slice(0, 260)}`);
    }
  } finally {
    const unexpectedConsoleErrors = unexplainedConsoleErrors();
    report.summary = {
      passed: report.checks.length, failed: report.failures.length,
      api_errors: report.api_errors.length,
      unexplained_api_errors: report.api_errors.filter((error) =>
        !/ 401 GET \/api\/v1\/auth\/user$/.test(error) && !(report.expected_api_errors ?? []).includes(error)).length,
      server_errors: report.http_errors.length, console_errors: report.console_errors.length,
      expected_console_errors: report.expected_console_errors.length,
      unexplained_console_errors: unexpectedConsoleErrors.length, page_errors: report.page_errors.length,
    };
    fs.mkdirSync(OUT, { recursive: true });
    fs.writeFileSync(path.join(OUT, 'report.json'), JSON.stringify(report, null, 2));
    console.log(`\n${report.summary.passed} passed, ${report.summary.failed} failed, ${report.summary.unexplained_api_errors} unexplained API errors (${report.summary.server_errors} 5xx), ${report.summary.expected_console_errors} expected API response console errors, ${report.summary.unexplained_console_errors} unexplained console errors, ${report.summary.page_errors} page errors → ${OUT}/report.json`);
    for (const f of report.failures) console.log(`  FAIL ${f.name} — ${f.evidence.slice(0, 220)}`);
    await browser?.close();
    await tearDown(server);
    process.exitCode = report.failures.length || report.summary.unexplained_api_errors || report.http_errors.length || unexpectedConsoleErrors.length || report.page_errors.length ? 1 : 0;
  }
})();
