// Browser-authenticated Production / Work Orders acceptance flow.
// The fixture creates prerequisite records only.
//
//   PRODUCTION_WORKORDERS_TEST_BOOTSTRAP=1 node scripts/production-workorders-headless.cjs
//
// With PRODUCTION_WORKORDERS_TEST_BOOTSTRAP=1 the runner is self-contained: it
// creates ogami_test_production_workorders_browser_<run>, migrates it, seeds
// the fixture (api/tests/Browser/production_workorders_fixture.php), serves a
// temporary API and Vite proxy, then tears both down. Without the flag it
// points at PRODUCTION_WORKORDERS_TEST_URL and PRODUCTION_WORKORDERS_FIXTURE_PATH.
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { pathToFileURL } = require('node:url');
const { createRequire } = require('node:module');

const ROOT = path.resolve(__dirname, '..');
const SPA = path.join(ROOT, 'spa');
const spaRequire = createRequire(path.join(SPA, 'package.json'));
const { chromium, expect } = spaRequire('@playwright/test');

const BOOTSTRAP = process.env.PRODUCTION_WORKORDERS_TEST_BOOTSTRAP === '1';
const RUN_KEY = (process.env.PRODUCTION_WORKORDERS_RUN_ID || `PWO${Date.now().toString(36)}`).toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 16);
const DB = `ogami_test_production_workorders_browser_${RUN_KEY.toLowerCase()}`;
const TEST_STORAGE = `/tmp/ogami-audit-storage/production-workorders-${RUN_KEY.toLowerCase()}`;
const API_PORT = Number(process.env.PRODUCTION_WORKORDERS_TEST_API_PORT || 8210);
const SPA_PORT = Number(process.env.PRODUCTION_WORKORDERS_TEST_SPA_PORT || 5210);
const BASE = process.env.PRODUCTION_WORKORDERS_TEST_URL || (BOOTSTRAP ? `http://127.0.0.1:${SPA_PORT}` : 'http://127.0.0.1:5210');
const OUT_ROOT = path.join('/tmp', `production-workorders-headless-${RUN_KEY}`);
const FIXTURE_IN_CONTAINER = `/tmp/pwo-${RUN_KEY.toLowerCase()}-fixture.json`;
const API_LOG_PATH = `/tmp/${RUN_KEY}-production-api.log`;
const ENV = {
  DB_DATABASE: DB, CACHE_STORE: 'array', QUEUE_CONNECTION: 'sync', MAIL_MAILER: 'array',
  BROADCAST_CONNECTION: 'log', SESSION_DRIVER: 'database', LOG_CHANNEL: 'stderr', APP_URL: BASE,
  LARAVEL_STORAGE_PATH: TEST_STORAGE,
  SANCTUM_STATEFUL_DOMAINS: `127.0.0.1:${SPA_PORT},localhost:${SPA_PORT}`,
  PRODUCTION_WORKORDERS_BROWSER_DB: DB, PRODUCTION_WORKORDERS_RUN_ID: RUN_KEY,
  PRODUCTION_WORKORDERS_FIXTURE_PATH: FIXTURE_IN_CONTAINER,
};
const compose = (args) => execFileSync('docker', ['compose', ...args], { cwd: ROOT, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'], maxBuffer: 64 * 1024 * 1024 });
const envFlags = () => Object.entries(ENV).flatMap(([key, value]) => ['-e', `${key}=${value}`]);
const artisan = (...args) => compose(['exec', '-T', ...envFlags(), 'api', 'php', 'artisan', ...args]);
const psql = (query, db = 'postgres') => compose(['exec', '-T', 'db', 'psql', '-U', 'ogami', '-d', db, '-At', '-c', query]).trim();
const apiIp = () => execFileSync('docker', ['inspect', '-f', '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}', 'ogami-api'], { encoding: 'utf8' }).trim();
const sleep = (ms) => { try { execFileSync('sleep', [String(ms / 1000)]); } catch { /* ignored */ } };

/** Free the port so a run killed before its teardown cannot poison this one. */
function stopApiServer() {
  for (const pattern of [`port=${API_PORT}`, `0.0.0.0:${API_PORT}`]) {
    try { compose(['exec', '-T', 'api', 'pkill', '-f', pattern]); } catch { /* not running */ }
  }
}

/**
 * Everything except the Vite proxy can run before the async main, so the
 * fixture manifest exists by the time the module builds its report.
 */
function prepare() {
  fs.mkdirSync(OUT_ROOT, { recursive: true });
  stopApiServer();
  psql(`CREATE DATABASE ${DB} OWNER ogami;`);
  compose(['exec', '-T', 'api', 'mkdir', '-p', `${TEST_STORAGE}/app`, `${TEST_STORAGE}/app/public`, `${TEST_STORAGE}/framework/cache/data`, `${TEST_STORAGE}/framework/sessions`, `${TEST_STORAGE}/framework/views`, `${TEST_STORAGE}/logs`]);
  const actualStorage = artisan('tinker', '--execute', 'echo storage_path();').trim();
  if (actualStorage !== TEST_STORAGE) throw new Error(`Expected isolated Laravel storage ${TEST_STORAGE}; got ${actualStorage}.`);
  artisan('migrate', '--force');
  compose(['exec', '-T', 'api', 'rm', '-f', FIXTURE_IN_CONTAINER]);
  artisan('tinker', '--execute', "require 'tests/Browser/production_workorders_fixture.php';");
  compose(['cp', `api:${FIXTURE_IN_CONTAINER}`, path.join(OUT_ROOT, 'fixture.json')]);
  compose(['exec', '-T', 'api', 'rm', '-f', API_LOG_PATH]);
  compose(['exec', '-d', ...envFlags(), 'api', 'sh', '-c', `php artisan serve --no-reload --host=0.0.0.0 --port=${API_PORT} > ${API_LOG_PATH} 2>&1`]);
  const target = `http://${apiIp()}:${API_PORT}`;
  for (let i = 0; i < 60; i++) {
    let status = '000';
    try { status = execFileSync('curl', ['-s', '-o', '/dev/null', '-w', '%{http_code}', `${target}/api/v1/auth/user`], { encoding: 'utf8' }).trim(); } catch { /* booting */ }
    if (status !== '000' && Number(status) < 500) return;
    sleep(1000);
  }
  throw new Error(`The API on :${API_PORT} never answered against ${DB}.`);
}

let viteServer;
async function startVite() {
  const vite = await import(pathToFileURL(path.join(SPA, 'node_modules/vite/dist/node/index.js')).href);
  // Tailwind resolves its content globs from the working directory, so run the
  // SPA toolchain from the SPA root; every runner path is absolute.
  process.chdir(SPA);
  const target = `http://${apiIp()}:${API_PORT}`;
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

async function tearDown(server) {
  try { await server?.close(); } catch { /* already closed */ }
  if (process.env.PRODUCTION_WORKORDERS_TEST_KEEP === '1' || process.exitCode) {
    console.log(`Kept ${DB} and API :${API_PORT} for inspection.`);
    return;
  }
  stopApiServer();
  try { compose(['exec', '-T', 'api', 'rm', '-f', FIXTURE_IN_CONTAINER, API_LOG_PATH]); } catch { /* gone */ }
  try {
    psql(`SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname='${DB}' AND pid <> pg_backend_pid();`);
    psql(`DROP DATABASE IF EXISTS ${DB};`);
  } catch (e) { console.log(`Could not drop ${DB}: ${e.message}`); }
  try { compose(['exec', '-T', 'api', 'rm', '-rf', TEST_STORAGE]); } catch (e) { console.log(`Could not remove ${TEST_STORAGE}: ${e.message}`); }
}

function captureApiServerLogs() {
  if (!BOOTSTRAP) return { captured: false, reason: 'The runner did not start an isolated API process.' };
  try {
    return { captured: true, path: API_LOG_PATH, text: compose(['exec', '-T', 'api', 'cat', API_LOG_PATH]) };
  } catch (error) {
    return { captured: false, path: API_LOG_PATH, error: String(error?.message ?? error) };
  }
}

if (BOOTSTRAP) prepare();
const FIXTURE_PATH = BOOTSTRAP ? path.join(OUT_ROOT, 'fixture.json') : process.env.PRODUCTION_WORKORDERS_FIXTURE_PATH;
if (!FIXTURE_PATH) throw new Error('Set PRODUCTION_WORKORDERS_FIXTURE_PATH to the fixture manifest.');
const fixture = JSON.parse(fs.readFileSync(FIXTURE_PATH, 'utf8'));
const RUN = fixture.run_id;
const OUT = process.env.PRODUCTION_WORKORDERS_TEST_OUTPUT
  || (BOOTSTRAP ? OUT_ROOT : path.join('/tmp', `production-workorders-headless-${RUN}-${Date.now()}`));
fs.mkdirSync(OUT, { recursive: BOOTSTRAP });

const report = {
  run_id: RUN,
  database: fixture.database,
  base_url: BASE,
  authentication: 'Sanctum session cookie + CSRF cookie; no Bearer token or localStorage auth',
  websocket: 'Inert Pusher-protocol transport acknowledges connect/subscription/ping only; no real-time business events are sent',
  checks: [],
  failures: [],
  handoffs: [],
  artifacts: [],
};
const live = [];
const jsErrors = [];
const diagnosticsByPage = new WeakMap();
const EXPECTED_HTTP_ERRORS = [
  { status: 401, method: 'GET', path: /^\/api\/v1\/auth\/user$/, reason: 'Unauthenticated SPA bootstrap before cookie sign-in.' },
  { status: 403, method: 'GET', path: /^\/api\/v1\/(?:attendance\/shifts|crm\/products|production\/work-orders)$/, reason: 'Role-scoped permission denial asserted by the form and list access checks.' },
  { status: 403, method: 'PATCH', path: /^\/api\/v1\/quality\/inspections\/[^/]+\/review$/, reason: 'Quality Inspector and Production Manager are denied self-review of inspections they performed.' },
  { status: 404, method: 'GET', path: /^\/api\/v1\/production\/my-work-orders\/[^/]+$/, reason: 'Unassigned worker cannot read another work order.' },
  { status: 409, method: 'POST', path: /^\/api\/v1\/production\/operations\/[^/]+\/complete$/, reason: 'Operation QC gate or duplicate-completion denial asserted by the operation chain.' },
  { status: 409, method: 'POST', path: /^\/api\/v1\/production\/work-orders\/[^/]+\/close$/, reason: 'Outgoing QC or duplicate-close denial asserted by the work-order chain.' },
  { status: 422, method: 'DELETE', path: /^\/api\/v1\/inventory\/material-issues\/[^/]+$/, reason: 'Post-output material cancellation is forbidden and state is checked unchanged.' },
  { status: 422, method: 'PATCH', path: /^\/api\/v1\/maintenance\/work-orders\/[^/]+\/start$/, reason: 'Maintenance cannot acquire equipment still owned by Production.' },
  { status: 422, method: 'POST', path: /^\/api\/v1\/production\/my-work-orders\/[^/]+\/outputs$/, reason: 'Assigned-worker output is blocked while required in-process QC is pending.' },
  { status: 422, method: 'POST', path: /^\/api\/v1\/production\/work-orders\/[^/]+\/(?:close|resume|start|outputs)$/, reason: 'Work-order QC, schedule, or material-coverage gate is asserted before retry.' },
  { status: 503, method: 'GET', path: /^\/api\/v1\/production\/work-orders\/form-options$/, reason: 'The browser test deliberately injects one form-options outage and verifies retry recovery.' },
];

function collectBrowserDiagnostics() {
  return live.map(({ page, email, diagnostics }) => ({
    email,
    url: page.url(),
    viewport: page.viewportSize(),
    ...diagnostics,
  }));
}

function classifyHttpErrors(browserDiagnostics) {
  return browserDiagnostics.flatMap((page) => page.errorResponses.map((response) => {
    const pathname = new URL(response.url).pathname;
    const expected = EXPECTED_HTTP_ERRORS.find((entry) =>
      entry.status === response.status && entry.method === response.method && entry.path.test(pathname));
    return {
      email: page.email,
      status: response.status,
      method: response.method,
      path: pathname,
      resourceType: response.resourceType,
      expected: !!expected,
      reason: expected?.reason ?? 'No matching asserted negative probe; investigate this response.',
    };
  }));
}

function recordDiagnosticEvidence() {
  report.browser_diagnostics = collectBrowserDiagnostics();
  report.http_error_inventory = classifyHttpErrors(report.browser_diagnostics);
  report.api_server_logs = captureApiServerLogs();
  report.diagnostic_summary = {
    pages: report.browser_diagnostics.length,
    pageErrors: report.browser_diagnostics.reduce((sum, page) => sum + page.pageErrors.length, 0),
    consoleErrors: report.browser_diagnostics.reduce((sum, page) => sum + page.consoleErrors.length, 0),
    failedRequests: report.browser_diagnostics.reduce((sum, page) => sum + page.failedRequests.length, 0),
    errorResponses: report.http_error_inventory.length,
    unclassifiedHttpErrors: report.http_error_inventory.filter((row) => !row.expected).length,
    crashes: report.browser_diagnostics.reduce((sum, page) => sum + page.crashes.length, 0),
    apiServerLogCaptured: report.api_server_logs.captured,
    apiServerLogLines: report.api_server_logs.text?.split('\n').filter(Boolean).length ?? null,
  };
}

function assertCleanDiagnostics() {
  const pageErrors = report.browser_diagnostics.flatMap((page) => page.pageErrors);
  const failedRequests = report.browser_diagnostics.flatMap((page) => page.failedRequests);
  const crashes = report.browser_diagnostics.flatMap((page) => page.crashes);
  const unexpectedConsoleErrors = report.browser_diagnostics.flatMap((page) => page.consoleErrors
    .filter((error) => !/^Failed to load resource: the server responded with a status of \d+/.test(error.text)));
  const unexpectedHttpErrors = report.http_error_inventory.filter((row) => !row.expected);
  const logCaptureFailed = BOOTSTRAP && !report.api_server_logs.captured;
  if (pageErrors.length || failedRequests.length || crashes.length || unexpectedConsoleErrors.length || unexpectedHttpErrors.length || logCaptureFailed) {
    throw new Error(`Unexpected browser or API diagnostics: ${JSON.stringify({
      pageErrors, failedRequests, crashes, unexpectedConsoleErrors, unexpectedHttpErrors,
      apiServerLogCapture: logCaptureFailed ? report.api_server_logs : null,
    })}`);
  }
}
let echoWebsocketSessions = 0;
let websocketSubscriptionsAcknowledged = 0;
let websocketPingsAnswered = 0;
const echoWebsocketUrls = [];
let sharedBrowser;

function passed(name, evidence = {}) {
  report.checks.push({ name, evidence });
  console.log(`PASS: ${name}`);
}

function dataOf(result) {
  return result.body?.data ?? result.body;
}

async function request(page, method, route, body, extraHeaders = {}) {
  return page.evaluate(async ({ method, route, body, extraHeaders }) => {
    const xsrf = document.cookie.split('; ').find((part) => part.startsWith('XSRF-TOKEN='))?.slice(11);
    const response = await fetch(`/api/v1${route}`, {
      method,
      credentials: 'include',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } : {}),
        ...extraHeaders,
      },
      ...(body !== undefined ? { body: JSON.stringify(body) } : {}),
    });
    const text = await response.text();
    let data;
    try { data = JSON.parse(text); } catch { data = { raw: text }; }
    return { status: response.status, body: data };
  }, { method, route, body, extraHeaders });
}

async function login(email, viewport = { width: 1440, height: 1000 }) {
  if (!sharedBrowser) sharedBrowser = await chromium.launch({ headless: true, args: ['--no-sandbox', '--disable-dev-shm-usage'] });
  const context = await sharedBrowser.newContext({ baseURL: BASE, viewport, timezoneId: 'Asia/Manila', reducedMotion: 'reduce' });
  await context.routeWebSocket((url) => {
    const candidate = new URL(url);
    return (candidate.port === '8080' || candidate.port === '443') && candidate.pathname.startsWith('/app/');
  }, (socket) => {
    echoWebsocketSessions++;
    echoWebsocketUrls.push(socket.url());
    socket.onMessage((message) => {
      if (typeof message !== 'string') return;
      let frame;
      try { frame = JSON.parse(message); } catch { return; }
      if (frame.event === 'pusher:ping') {
        websocketPingsAnswered++;
        socket.send(JSON.stringify({ event: 'pusher:pong', data: {} }));
      } else if (frame.event === 'pusher:subscribe' && frame.data?.channel) {
        websocketSubscriptionsAcknowledged++;
        socket.send(JSON.stringify({
          event: 'pusher_internal:subscription_succeeded',
          channel: frame.data.channel,
          data: '{}',
        }));
      }
    });
    socket.send(JSON.stringify({
      event: 'pusher:connection_established',
      data: JSON.stringify({ socket_id: `9900925.${echoWebsocketSessions}`, activity_timeout: 120 }),
    }));
  });
  const page = await context.newPage();
  page.setDefaultTimeout(20_000);
  const diagnostics = { pageErrors: [], consoleErrors: [], failedRequests: [], errorResponses: [], mainFrameNavigations: [], websocketConnections: [], crashes: [] };
  diagnosticsByPage.set(page, diagnostics);
  const appendDiagnostic = (rows, value) => {
    if (rows.length < 100) rows.push(value);
  };
  page.on('pageerror', (error) => {
    jsErrors.push(`${email}: ${error.message}`);
    appendDiagnostic(diagnostics.pageErrors, { message: error.message, stack: error.stack ?? null, url: page.url() });
  });
  page.on('console', (message) => {
    if (message.type() === 'error') appendDiagnostic(diagnostics.consoleErrors, {
      text: message.text(), url: message.location().url || null, line: message.location().lineNumber || null,
    });
  });
  page.on('requestfailed', (request) => appendDiagnostic(diagnostics.failedRequests, {
    url: request.url(), method: request.method(), resourceType: request.resourceType(), failure: request.failure()?.errorText ?? null,
  }));
  page.on('response', (response) => {
    if (response.status() >= 400) appendDiagnostic(diagnostics.errorResponses, {
      status: response.status(), url: response.url(), method: response.request().method(),
      resourceType: response.request().resourceType(),
    });
  });
  page.on('framenavigated', (frame) => {
    if (frame === page.mainFrame()) appendDiagnostic(diagnostics.mainFrameNavigations, { url: frame.url(), at: new Date().toISOString() });
  });
  page.on('crash', () => appendDiagnostic(diagnostics.crashes, { url: page.url(), at: new Date().toISOString() }));
  page.on('websocket', (websocket) => appendDiagnostic(diagnostics.websocketConnections, {
    url: websocket.url(), isViteHmr: new URL(websocket.url()).port === String(SPA_PORT),
  }));
  await page.goto('/sign-in');
  await page.getByLabel('Email', { exact: true }).fill(email);
  await page.getByLabel('Password', { exact: true }).fill(process.env.PRODUCTION_WORKORDERS_TEST_PASSWORD || 'password');
  const responsePromise = page.waitForResponse((response) =>
    response.url().endsWith('/api/v1/auth/sign-in') && response.request().method() === 'POST');
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  const response = await responsePromise;
  const body = await response.text();
  expect(response.status(), `${email} login: ${body}`).toBe(200);
  await expect(page).not.toHaveURL(/\/sign-in$/);
  const authStorage = await page.evaluate(() => ({
    local: Object.keys(localStorage),
    session: Object.keys(sessionStorage),
    cookies: document.cookie.split(';').map((part) => part.trim().split('=')[0]),
  }));
  expect(authStorage.local.some((key) => /token|auth/i.test(key))).toBe(false);
  expect(authStorage.session.some((key) => /token|auth/i.test(key))).toBe(false);
  expect(authStorage.cookies).toContain('XSRF-TOKEN');
  live.push({ context, page, email, diagnostics });
  passed(`Real cookie + CSRF login: ${email}`, { authStorage });
  return page;
}

async function screenshot(page, name, fullPage = true) {
  const file = path.join(OUT, name);
  await page.screenshot({ path: file, fullPage });
  report.artifacts.push(file);
  return file;
}

async function assertMobileFit(page, label, requireScrollableTables = false) {
  const metrics = await page.evaluate(() => ({
    viewport: document.documentElement.clientWidth,
    document: document.documentElement.scrollWidth,
    body: document.body.scrollWidth,
    tablePanels: Array.from(document.querySelectorAll('.overflow-x-auto'))
      .filter((panel) => panel.querySelector('table'))
      .map((panel) => ({ clientWidth: panel.clientWidth, scrollWidth: panel.scrollWidth })),
  }));
  expect(metrics.document, `${label} must not overflow the 390px viewport`).toBeLessThanOrEqual(metrics.viewport + 1);
  expect(metrics.body, `${label} body must not overflow the viewport`).toBeLessThanOrEqual(metrics.viewport + 1);
  if (requireScrollableTables) {
    expect(metrics.tablePanels.length, `${label} should render table panels`).toBeGreaterThan(0);
    expect(metrics.tablePanels.every((panel) => panel.clientWidth <= metrics.viewport + 1), `${label} table panels must stay within the viewport`).toBe(true);
    expect(metrics.tablePanels.some((panel) => panel.scrollWidth > panel.clientWidth), `${label} tables should scroll inside their panels`).toBe(true);
  }
  passed(`390px responsive layout: ${label}`, metrics);
}

async function at(page, method, route, body, expectedStatus = 200) {
  const result = await request(page, method, route, body);
  expect(result.status, `${method} ${route}: ${JSON.stringify(result.body)}`).toBe(expectedStatus);
  return result;
}

async function stockAt(page, itemId, locationId) {
  const result = await at(page, 'GET', `/inventory/stock-levels?item_id=${encodeURIComponent(itemId)}&per_page=100`);
  const rows = dataOf(result);
  const row = Array.isArray(rows) ? rows.find((value) => value.location?.id === locationId) : null;
  expect(row, `stock row for ${itemId} / ${locationId}`).toBeTruthy();
  return row;
}

async function createIssueInUi(warehouse, workOrderId, quantity, lotNumber, expectedWoNumber, funding, selectionMode = 'pointer') {
  await warehouse.goto(`/inventory/material-issues/create?work_order_id=${encodeURIComponent(workOrderId)}`);
  await expect(warehouse.getByRole('heading', { name: 'New material issue' })).toBeVisible();
  const form = warehouse.locator('form');
  const workOrderSelect = form.locator('select[name="work_order_id"]');
  const workOrderPicker = form.getByRole('combobox', { name: 'Work order', exact: true });
  await expect(workOrderSelect).toHaveValue(workOrderId);
  await expect(workOrderPicker).toHaveValue(new RegExp(expectedWoNumber));
  const workOrderOptions = await workOrderSelect.locator('option').allTextContents();
  expect(workOrderOptions.join(' ')).toContain(expectedWoNumber);
  const itemPicker = form.getByRole('combobox', { name: 'Item for line 1', exact: true });
  await itemPicker.fill(fixture.raw_item_code);
  const itemOption = warehouse.getByRole('listbox', { name: 'Item for line 1' })
    .getByRole('option').filter({ hasText: fixture.raw_item_code }).first();
  await expect(itemOption).toBeVisible();
  await itemOption.click();
  const location = form.getByRole('combobox', { name: 'Location for line 1', exact: true });
  await expect(location).toBeEnabled();
  await location.fill('');
  const locationList = warehouse.getByRole('listbox', { name: 'Location for line 1' });
  const firstLocationOption = locationList.getByRole('option').first();
  await expect(firstLocationOption).toBeVisible();
  const locationLabel = (await firstLocationOption.innerText()).trim();
  expect(locationLabel).not.toBe('');
  await location.fill(locationLabel);
  const locationOption = locationList.getByRole('option')
    .filter({ hasText: locationLabel.split(' — ')[0] }).first();
  await expect(locationOption).toBeVisible();
  if (selectionMode === 'keyboard') {
    await location.press('ArrowDown');
    await location.press('Enter');
  } else {
    await locationOption.click();
  }
  await expect(location).toHaveValue(locationLabel);
  await form.getByPlaceholder('Lot (optional)').fill(lotNumber);
  await form.getByPlaceholder('0').fill(String(quantity));
  await expect(form.getByRole('button', { name: 'Create issue', exact: true })).toBeEnabled();
  const requestBodies = [];
  warehouse.on('request', (request) => {
    if (request.url().endsWith('/api/v1/inventory/material-issues') && request.method() === 'POST') {
      requestBodies.push(request.postDataJSON());
    }
  });
  const responsePromise = warehouse.waitForResponse((response) =>
    response.url().endsWith('/api/v1/inventory/material-issues') && response.request().method() === 'POST');
  await form.getByRole('button', { name: 'Create issue', exact: true }).click();
  const response = await responsePromise;
  const payload = await response.json();
  expect(response.status(), JSON.stringify(payload)).toBe(201);
  expect(requestBodies).toHaveLength(1);
  expect(requestBodies[0].work_order_id).toBe(workOrderId);
  expect(requestBodies[0].items[0].location_id).toBe(fixture.raw_location_id);
  expect(requestBodies[0].items[0].lot_number).toBe(lotNumber);
  if (funding === 'reservation') {
    expect(requestBodies[0].items[0].material_reservation_id).toBeTruthy();
  } else {
    expect(requestBodies[0].items[0].material_reservation_id).toBeUndefined();
  }
  const slip = payload.data;
  await expect(warehouse).toHaveURL(new RegExp(`/inventory/material-issues/${slip.id}$`));
  await expect(warehouse.getByText(expectedWoNumber, { exact: true })).toBeVisible();
  return { slip, request: requestBodies[0] };
}

async function confirmViaUi(manager, warehouse, workOrderId, workOrderNumber, expectedReserved) {
  await manager.goto(`/production/work-orders/${workOrderId}`);
  await expect(manager.getByRole('button', { name: 'Confirm', exact: true })).toBeVisible();
  await manager.getByRole('button', { name: 'Confirm', exact: true }).click();
  const dialog = manager.getByRole('dialog');
  await dialog.getByLabel('Number of workers', { exact: true }).selectOption('1');
  const apply = dialog.getByRole('button', { name: 'Apply assignment and confirm', exact: true });
  await expect(apply).toBeEnabled({ timeout: 20_000 });
  const responsePromise = manager.waitForResponse((response) =>
    response.url().endsWith(`/api/v1/production/work-orders/${workOrderId}/assignment-apply`)
      && response.request().method() === 'POST');
  await apply.click();
  const response = await responsePromise;
  const body = await response.json();
  expect(response.status(), JSON.stringify(body)).toBe(200);
  const workOrder = dataOf(await at(manager, 'GET', `/production/work-orders/${workOrderId}`));
  expect(workOrder.status).toBe('confirmed');
  expect(workOrder.machine.id).toBe(fixture.machine_id);
  expect(workOrder.mold.id).toBe(fixture.mold_id);
  const assignment = dataOf(await at(manager, 'GET', `/production/work-orders/${workOrderId}/crew`));
  expect(assignment.current.members).toHaveLength(1);
  expect(assignment.current.recorder_employee_id).toBe(fixture.output_operator_id);
  expect(assignment.schedule.status).toBe('confirmed');
  expect(assignment.schedule.machine_id).toBe(fixture.machine_id);
  expect(assignment.schedule.mold_id).toBe(fixture.mold_id);
  const rawStock = await stockAt(warehouse, fixture.raw_item_id, fixture.raw_location_id);
  expect(Number(rawStock.quantity)).toBe(8);
  expect(Number(rawStock.reserved_quantity)).toBe(expectedReserved);
  expect(Number(rawStock.available)).toBe(8 - expectedReserved);
  const statusAuditCount = Number(psql(`SELECT count(*) FROM audit_logs
    WHERE model_type LIKE '%Production%WorkOrder'
      AND model_id = (SELECT id FROM work_orders WHERE wo_number = '${workOrderNumber}')
      AND action = 'updated' AND new_values->>'status' = 'confirmed'
      AND user_id = (SELECT id FROM users WHERE email = 'production@ogami.test')`, DB));
  expect(statusAuditCount).toBeGreaterThan(0);
  const chainStepCount = Number(psql(`SELECT count(*) FROM chain_step_runs
    WHERE chain = 'o2c' AND entity_type = 'work_order'
      AND entity_id = (SELECT id FROM work_orders WHERE wo_number = '${workOrderNumber}')
      AND step = 'confirmed'`, DB));
  expect(chainStepCount).toBeGreaterThan(0);
  await expect(manager.locator('h1').getByText('Confirmed', { exact: true })).toBeVisible();
  await expect(manager.getByRole('button', { name: 'Start', exact: true })).toBeVisible();
  return workOrder;
}

async function startViaUi(manager, workOrderId, expectedStatus = 200) {
  await manager.goto(`/production/work-orders/${workOrderId}`);
  await expect(manager.getByRole('button', { name: 'Start', exact: true })).toBeVisible({ timeout: 20_000 });
  await manager.getByRole('button', { name: 'Start', exact: true }).click();
  const dialog = manager.getByRole('dialog');
  const responsePromise = manager.waitForResponse((response) =>
    response.url().endsWith(`/api/v1/production/work-orders/${workOrderId}/start`));
  await dialog.getByRole('button', { name: 'Start', exact: true }).click();
  const response = await responsePromise;
  const body = await response.json();
  expect(response.status(), JSON.stringify(body)).toBe(expectedStatus);
  if (expectedStatus === 200) await expect(manager.getByText('In Progress', { exact: true })).toBeVisible();
  return { response, body };
}

async function reviewSlotViaUi(manager, workOrderId, workOrderNumber, {
  startOffsetMinutes = -5,
  endOffsetMinutes = 120,
} = {}) {
  await manager.goto(`/production/work-orders/${workOrderId}`);
  await expect(manager.getByRole('button', { name: 'Review production slot', exact: true })).toBeVisible();
  const before = dataOf(await at(manager, 'GET', `/production/work-orders/${workOrderId}/crew`));
  if (before.schedule) expect(before.schedule.status).toBe('confirmed');

  await manager.getByRole('button', { name: 'Review production slot', exact: true }).click();
  const dialog = manager.getByRole('dialog');
  const slot = await manager.evaluate(({ startOffsetMinutes, endOffsetMinutes }) => {
    const inputValue = (date) => {
      const local = new Date(date.getTime() - date.getTimezoneOffset() * 60_000);
      return local.toISOString().slice(0, 16);
    };
    const start = inputValue(new Date(Date.now() + startOffsetMinutes * 60_000));
    const end = inputValue(new Date(Date.now() + endOffsetMinutes * 60_000));
    return {
      start,
      end,
      startEpoch: new Date(`${start}:00+08:00`).getTime(),
      endEpoch: new Date(`${end}:00+08:00`).getTime(),
    };
  }, { startOffsetMinutes, endOffsetMinutes });
  const reason = `Audit reviewed active slot for ${workOrderNumber} starting ${slot.start}`;
  await dialog.getByLabel('Reviewed production start', { exact: true }).fill(slot.start);
  await dialog.getByLabel('Reviewed production end', { exact: true }).fill(slot.end);
  await dialog.getByLabel('Scheduling reason', { exact: true }).fill(reason);
  const reviewRequests = [];
  manager.on('request', (request) => {
    if (request.url().endsWith(`/api/v1/production/work-orders/${workOrderId}/review-slot`)
      && request.method() === 'POST') reviewRequests.push(request.postDataJSON());
  });
  const responsePromise = manager.waitForResponse((response) =>
    response.url().endsWith(`/api/v1/production/work-orders/${workOrderId}/review-slot`)
      && response.request().method() === 'POST');
  await dialog.getByRole('button', { name: 'Approve production slot', exact: true }).click();
  const response = await responsePromise;
  const body = await response.json();
  expect(response.status(), JSON.stringify(body)).toBe(200);
  expect(reviewRequests).toHaveLength(1);
  expect(reviewRequests[0].reason).toBe(reason);
  await expect(dialog).toHaveCount(0);

  const after = dataOf(await at(manager, 'GET', `/production/work-orders/${workOrderId}/crew`));
  expect(after.schedule.status).toBe('confirmed');
  if (before.schedule) expect(after.schedule.id).not.toBe(before.schedule.id);
  expect(Date.parse(after.schedule.scheduled_start)).toBe(slot.startEpoch);
  expect(Date.parse(after.schedule.scheduled_end)).toBe(slot.endEpoch);
  expect(after.schedule.machine_id).toBe(fixture.machine_id);
  expect(after.schedule.mold_id).toBe(fixture.mold_id);
  const reservingSlots = Number(psql(`SELECT count(*) FROM production_schedules
    WHERE work_order_id = (SELECT id FROM work_orders WHERE wo_number = '${workOrderNumber}')
      AND rework_ncr_id IS NULL AND reserves_capacity = TRUE`, fixture.database));
  expect(reservingSlots).toBe(1);
  const reviewEvents = Number(psql(`SELECT count(*) FROM activity_events
    WHERE type = 'production.schedule' AND action = 'reviewed'
      AND actor_user_id = (SELECT id FROM users WHERE email = 'production@ogami.test')
      AND subject_id = (SELECT id FROM work_orders WHERE wo_number = '${workOrderNumber}')
      AND detail->>'schedule_id' = '${after.schedule.id}'
      AND detail->>'reason' = '${reason}'`, fixture.database));
  expect(reviewEvents).toBe(1);
  passed('Production Manager reviews a current slot through the UI; one confirmed capacity reservation and audit event are recorded', {
    workOrder: workOrderNumber,
    previousSchedule: before.schedule?.id ?? null,
    reviewedSchedule: after.schedule.id,
    scheduledStart: after.schedule.scheduled_start,
    scheduledEnd: after.schedule.scheduled_end,
    reservingSlots,
    reviewEvents,
  });
  return after.schedule;
}

async function releasePausedResourcesViaUi(manager, workOrderId, reason) {
  await manager.goto(`/production/work-orders/${workOrderId}`);
  await expect(manager.getByRole('button', { name: 'Release resources', exact: true })).toBeVisible();
  await manager.getByRole('button', { name: 'Release resources', exact: true }).click();
  const dialog = manager.getByRole('dialog');
  await dialog.getByLabel('Release reason', { exact: true }).fill(reason);
  const responsePromise = manager.waitForResponse((response) =>
    response.url().endsWith(`/api/v1/production/work-orders/${workOrderId}/release-resources`)
      && response.request().method() === 'POST');
  await dialog.getByRole('button', { name: 'Release resources', exact: true }).click();
  const response = await responsePromise;
  const body = await response.json();
  expect(response.status(), JSON.stringify(body)).toBe(200);
  await expect(dialog).toHaveCount(0);
  return body.data;
}

async function maintenanceStartViaUi(page, workOrderId, expectedStatus) {
  await page.goto(`/maintenance/work-orders/${workOrderId}`);
  await expect(page.getByRole('button', { name: 'Start', exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'Start', exact: true }).click();
  const dialog = page.getByRole('dialog');
  const responsePromise = page.waitForResponse((response) =>
    response.url().endsWith(`/api/v1/maintenance/work-orders/${workOrderId}/start`)
      && response.request().method() === 'PATCH');
  await dialog.getByRole('button', { name: 'Start', exact: true }).click();
  const response = await responsePromise;
  const body = await response.json();
  expect(response.status(), JSON.stringify(body)).toBe(expectedStatus);
  if (expectedStatus === 200) {
    await expect(page.getByText('Work order started.', { exact: true })).toBeVisible();
  } else {
    await expect(page.getByText(/cannot start while the machine is assigned to active production/i)).toBeVisible();
    await dialog.getByRole('button', { name: 'Cancel', exact: true }).click();
  }
  return { response, body };
}

async function maintenanceCompleteViaUi(page, workOrderId, remarks) {
  await page.goto(`/maintenance/work-orders/${workOrderId}`);
  await expect(page.getByRole('button', { name: 'Complete', exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'Complete', exact: true }).click();
  const dialog = page.getByRole('dialog');
  await dialog.getByLabel('Remarks (optional)', { exact: true }).fill(remarks);
  const responsePromise = page.waitForResponse((response) =>
    response.url().endsWith(`/api/v1/maintenance/work-orders/${workOrderId}/complete`)
      && response.request().method() === 'PATCH');
  await dialog.getByRole('button', { name: 'Confirm complete', exact: true }).click();
  const response = await responsePromise;
  const body = await response.json();
  expect(response.status(), JSON.stringify(body)).toBe(200);
  await expect(dialog).toHaveCount(0);
  return body.data;
}

async function resumeViaUi(manager, workOrderId, expectedStatus = 200) {
  await manager.goto(`/production/work-orders/${workOrderId}`);
  await expect(manager.getByRole('button', { name: 'Resume', exact: true })).toBeVisible();
  await manager.getByRole('button', { name: 'Resume', exact: true }).click();
  const dialog = manager.getByRole('dialog');
  const responsePromise = manager.waitForResponse((response) =>
    response.url().endsWith(`/api/v1/production/work-orders/${workOrderId}/resume`)
      && response.request().method() === 'POST');
  await dialog.getByRole('button', { name: 'Resume', exact: true }).click();
  const response = await responsePromise;
  const body = await response.json();
  expect(response.status(), JSON.stringify(body)).toBe(expectedStatus);
  if (expectedStatus === 200) {
    await expect(manager.getByText('In Progress', { exact: true })).toBeVisible();
  } else {
    expect(body.message).toMatch(/waiting for in-process QC/i);
    await expect(manager.getByText(/waiting for in-process QC/i)).toBeVisible();
    await dialog.getByRole('button', { name: 'Cancel', exact: true }).click();
  }
  return expectedStatus === 200 ? body.data : body;
}

async function resumeState(manager, warehouse, workOrderId, workOrderNumber, qc) {
  const workOrder = dataOf(await at(manager, 'GET', `/production/work-orders/${workOrderId}`));
  const stock = await stockAt(warehouse, fixture.raw_item_id, fixture.raw_location_id);
  const crew = dataOf(await at(manager, 'GET', `/production/work-orders/${workOrderId}/crew`));
  const inspections = dataOf(await at(qc, 'GET', `/quality/inspections?entity_type=work_order&entity_id=${encodeURIComponent(workOrderId)}&per_page=100`));
  return {
    workOrder: {
      status: workOrder.status,
      productionReleasedAt: workOrder.production_released_at ?? null,
      actualStart: workOrder.actual_start ?? null,
      quantityProduced: Number(workOrder.quantity_produced),
      quantityGood: Number(workOrder.quantity_good),
      quantityRejected: Number(workOrder.quantity_rejected),
    },
    stock: {
      quantity: Number(stock.quantity),
      reserved: Number(stock.reserved_quantity),
      available: Number(stock.available),
    },
    machine: psql(`SELECT m.status || ':' || COALESCE(w.wo_number, '') FROM machines m
      LEFT JOIN work_orders w ON w.id = m.current_work_order_id WHERE m.machine_code = '${fixture.machine_code}'`, fixture.database),
    mold: psql(`SELECT m.status || ':' || COALESCE(w.wo_number, '') FROM molds m
      LEFT JOIN work_orders w ON w.id = m.current_work_order_id WHERE m.mold_code = '${fixture.mold_code}'`, fixture.database),
    currentCrewId: crew.current?.id ?? null,
    schedule: crew.schedule ? {
      id: crew.schedule.id,
      status: crew.schedule.status,
      start: crew.schedule.scheduled_start,
      end: crew.schedule.scheduled_end,
    } : null,
    inProcessInspections: inspections.filter((inspection) => inspection.stage === 'in_process')
      .map((inspection) => ({ id: inspection.id, status: inspection.status })),
    activeSchedules: Number(psql(`SELECT count(*) FROM production_schedules
      WHERE work_order_id = (SELECT id FROM work_orders WHERE wo_number = '${workOrderNumber}')
        AND rework_ncr_id IS NULL AND reserves_capacity = TRUE AND is_confirmed = TRUE AND status = 'confirmed'`, fixture.database)),
    openDowntime: Number(psql(`SELECT count(*) FROM machine_downtimes
      WHERE work_order_id = (SELECT id FROM work_orders WHERE wo_number = '${workOrderNumber}') AND end_time IS NULL`, fixture.database)),
    inProgressChainSteps: Number(psql(`SELECT count(*) FROM chain_step_runs
      WHERE chain = 'o2c' AND entity_type = 'work_order'
        AND entity_id = (SELECT id FROM work_orders WHERE wo_number = '${workOrderNumber}')
        AND step = 'in_progress'`, fixture.database)),
    inProgressAuditEvents: Number(psql(`SELECT count(*) FROM audit_logs
      WHERE model_type LIKE '%Production%WorkOrder'
        AND model_id = (SELECT id FROM work_orders WHERE wo_number = '${workOrderNumber}')
        AND action = 'updated' AND user_id = (SELECT id FROM users WHERE email = 'production@ogami.test')
        AND new_values->>'status' = 'in_progress'`, fixture.database)),
  };
}

async function startState(manager, warehouse, workOrderId, workOrderNumber) {
  const workOrder = dataOf(await at(manager, 'GET', `/production/work-orders/${workOrderId}`));
  const stock = await stockAt(warehouse, fixture.raw_item_id, fixture.raw_location_id);
  return {
    workOrder: {
      status: workOrder.status,
      actualStart: workOrder.actual_start ?? null,
      target: Number(workOrder.quantity_target),
      produced: Number(workOrder.quantity_produced),
      good: Number(workOrder.quantity_good),
      rejected: Number(workOrder.quantity_rejected),
      issued: Number(workOrder.materials[0]?.actual_quantity_issued ?? 0),
    },
    stock: {
      quantity: Number(stock.quantity),
      reserved: Number(stock.reserved_quantity),
      available: Number(stock.available),
    },
    machine: psql(`SELECT m.status || ':' || COALESCE(w.wo_number, '') FROM machines m
      LEFT JOIN work_orders w ON w.id = m.current_work_order_id WHERE m.machine_code = '${fixture.machine_code}'`, fixture.database),
    mold: psql(`SELECT m.status || ':' || COALESCE(w.wo_number, '') FROM molds m
      LEFT JOIN work_orders w ON w.id = m.current_work_order_id WHERE m.mold_code = '${fixture.mold_code}'`, fixture.database),
    startedChainSteps: Number(psql(`SELECT count(*) FROM chain_step_runs
      WHERE chain = 'o2c' AND entity_type = 'work_order'
        AND entity_id = (SELECT id FROM work_orders WHERE wo_number = '${workOrderNumber}')
        AND step = 'in_progress'`, fixture.database)),
  };
}

async function outputViaUi(worker, manager, warehouse, workOrderId, goodCount, rejectCount, defectId, label, expectedCounters, expectedFgQuantity) {
  await worker.goto(`/self-service/work-orders/${workOrderId}`);
  await expect(worker.getByRole('heading', { name: 'Record output', exact: true })).toBeVisible();
  await expect(worker.getByRole('listitem').filter({ hasText: 'Output recorder' })).toHaveCount(1);
  await expect(worker.getByRole('spinbutton', { name: 'Good count' })).toBeVisible();
  await worker.getByRole('spinbutton', { name: 'Good count' }).fill(String(goodCount));
  await worker.getByRole('spinbutton', { name: 'Reject count' }).fill(String(rejectCount));
  if (rejectCount > 0) {
    await worker.getByRole('button', { name: 'Add defect', exact: true }).click();
    await worker.getByRole('combobox', { name: 'Defect type 1' }).selectOption(defectId);
    await worker.getByRole('spinbutton', { name: 'Defect count 1' }).fill(String(rejectCount));
  }
  if (label === 'partial' || goodCount === 3) {
    await screenshot(worker, `${label}-record-output-form.png`);
    if ((await worker.evaluate(() => innerWidth)) <= 390) await assertMobileFit(worker, 'self-service record-output form');
  }
  const outputRoute = `/api/v1/production/my-work-orders/${workOrderId}/outputs`;
  const responsePromise = worker.waitForResponse((response) =>
    response.url().endsWith(outputRoute) && response.request().method() === 'POST');
  await worker.getByRole('button', { name: 'Record output', exact: true }).click();
  const response = await responsePromise;
  const body = await response.json();
  expect(response.status(), JSON.stringify(body)).toBe(200);
  expect(body.data.good_count).toBe(goodCount);
  expect(body.data.reject_count).toBe(rejectCount);
  expect(body.data.receipt_status).toBe('generated');
  await expect(worker.getByText(`Output ${body.data.batch_code} recorded.`, { exact: true })).toBeVisible();
  await expect(worker.getByText(body.data.batch_code, { exact: false })).toBeVisible();

  const workerState = dataOf(await at(worker, 'GET', `/production/my-work-orders/${workOrderId}`));
  expect(workerState.can_record).toBe(true);
  expect(workerState.status).toBe('in_progress');
  expect(Number(workerState.quantity_good)).toBe(expectedCounters.good);
  expect(Number(workerState.quantity_rejected)).toBe(expectedCounters.reject);
  const workOrder = dataOf(await at(manager, 'GET', `/production/work-orders/${workOrderId}`));
  expect(Number(workOrder.quantity_target)).toBe(expectedCounters.target);
  expect(Number(workOrder.quantity_produced)).toBe(expectedCounters.produced);
  expect(Number(workOrder.quantity_good)).toBe(expectedCounters.good);
  expect(Number(workOrder.quantity_rejected)).toBe(expectedCounters.reject);
  const outputs = dataOf(await at(manager, 'GET', `/production/work-orders/${workOrderId}/outputs`));
  const saved = outputs.find((output) => output.id === body.data.id);
  expect(saved, `persisted output ${body.data.batch_code}`).toBeTruthy();
  expect(saved.batch_code).toBe(body.data.batch_code);
  expect(saved.recorder?.name).toBeTruthy();
  expect(saved.crew_snapshot?.members?.length).toBeGreaterThan(0);
  const detailOutput = workOrder.outputs.find((output) => output.id === saved.id);
  expect(detailOutput?.crew_snapshot).toEqual(saved.crew_snapshot);
  await manager.goto(`/production/work-orders/${workOrderId}`);
  const detailRow = manager.getByRole('row').filter({ hasText: saved.batch_code });
  await expect(detailRow).toContainText(saved.crew_snapshot.members[0].name);
  await expect(detailRow).not.toContainText('Crew not recorded');
  expect(saved.production_receipt_handoff.status).toBe('generated');
  const recordedEmail = psql(`SELECT u.email FROM work_order_outputs o JOIN users u ON u.id = o.recorded_by WHERE o.batch_code = '${body.data.batch_code}'`, DB);
  expect(recordedEmail).toBe(fixture.output_operator_email);
  const receiptState = psql(`SELECT sm.gl_handoff_status::text || '|' || COALESCE(je.status::text, '') FROM stock_movements sm LEFT JOIN journal_entries je ON je.id = sm.journal_entry_id JOIN work_order_outputs o ON o.production_receipt_movement_id = sm.id WHERE o.batch_code = '${body.data.batch_code}'`, DB);
  expect(receiptState).toBe('generated|posted');
  const receiptBalanced = psql(`SELECT CASE WHEN round(sum(line.debit), 2) = round(sum(line.credit), 2) THEN 'balanced' ELSE 'unbalanced' END FROM work_order_outputs o JOIN stock_movements sm ON sm.id = o.production_receipt_movement_id JOIN journal_entry_lines line ON line.journal_entry_id = sm.journal_entry_id WHERE o.batch_code = '${body.data.batch_code}'`, DB);
  expect(receiptBalanced).toBe('balanced');
  const outputAuditCount = Number(psql(`SELECT count(*) FROM audit_logs a JOIN work_order_outputs o ON a.model_id = o.id WHERE o.batch_code = '${body.data.batch_code}' AND a.model_type LIKE '%WorkOrderOutput%'`, DB));
  expect(outputAuditCount).toBeGreaterThan(0);
  const finishedStock = await stockAt(warehouse, fixture.finished_item_id, fixture.fg_location_id);
  expect(Number(finishedStock.quantity)).toBe(expectedFgQuantity);
  if ((await worker.evaluate(() => innerWidth)) <= 390) await assertMobileFit(worker, 'self-service work-order after output');
  return saved;
}

async function outputViaManagerUi(manager, warehouse, workOrderId, workOrderNumber, goodCount, recordingReason, expectedCounters, expectedFgQuantity) {
  await manager.goto(`/production/work-orders/${workOrderId}/record-output`);
  await expect(manager.getByRole('heading', { name: new RegExp(`Record output — ${workOrderNumber}`) })).toBeVisible();
  await manager.getByLabel(/^Good count\s?\*?$/).fill(String(goodCount));
  await manager.getByLabel('Recording on behalf reason').fill(recordingReason);
  const responsePromise = manager.waitForResponse((response) =>
    response.url().endsWith(`/api/v1/production/work-orders/${workOrderId}/outputs`)
      && response.request().method() === 'POST');
  await manager.getByRole('button', { name: 'Record', exact: true }).click();
  const response = await responsePromise;
  const body = await response.json();
  expect(response.status(), JSON.stringify(body)).toBe(201);
  expect(body.data.good_count).toBe(goodCount);
  expect(body.data.reject_count).toBe(0);
  expect(body.data.recording_reason).toBe(recordingReason);

  const outputs = dataOf(await at(manager, 'GET', `/production/work-orders/${workOrderId}/outputs`));
  const saved = outputs.find((output) => output.id === body.data.id);
  expect(saved).toBeTruthy();
  expect(saved.recorder?.name).toBeTruthy();
  expect(saved.crew_snapshot?.members?.length).toBeGreaterThan(0);
  expect(saved.recording_reason).toBe(recordingReason);
  expect(saved.production_receipt_handoff.status).toBe('generated');
  const detail = dataOf(await at(manager, 'GET', `/production/work-orders/${workOrderId}`));
  expect(detail.status).toBe('in_progress');
  expect(Number(detail.quantity_target)).toBe(expectedCounters.target);
  expect(Number(detail.quantity_produced)).toBe(expectedCounters.produced);
  expect(Number(detail.quantity_good)).toBe(expectedCounters.good);
  expect(Number(detail.quantity_rejected)).toBe(expectedCounters.reject);
  const detailOutput = detail.outputs.find((output) => output.id === saved.id);
  expect(detailOutput?.crew_snapshot).toEqual(saved.crew_snapshot);
  expect(detailOutput?.recording_reason).toBe(recordingReason);
  const receiptState = psql(`SELECT sm.gl_handoff_status::text || '|' || COALESCE(je.status::text, '')
    FROM stock_movements sm LEFT JOIN journal_entries je ON je.id = sm.journal_entry_id
    JOIN work_order_outputs o ON o.production_receipt_movement_id = sm.id
    WHERE o.batch_code = '${saved.batch_code}'`, DB);
  expect(receiptState).toBe('generated|posted');
  const finishedStock = await stockAt(warehouse, fixture.finished_item_id, fixture.fg_location_id);
  expect(Number(finishedStock.quantity)).toBe(expectedFgQuantity);

  await manager.goto(`/production/work-orders/${workOrderId}`);
  const detailRow = manager.getByRole('row').filter({ hasText: saved.batch_code });
  await expect(detailRow).toContainText(saved.crew_snapshot.members[0].name);
  await expect(detailRow).toContainText(recordingReason);
  await expect(detailRow).not.toContainText('Crew not recorded');
  passed('Production Manager records output on behalf with required reason; detail shows the recorded crew and reason', {
    batch: saved.batch_code,
    crew: saved.crew_snapshot.members.map((member) => member.name),
    recordedBy: saved.recorder.name,
    recordingReason: saved.recording_reason,
    gl: receiptState,
    finishedGoods: finishedStock.quantity,
  });
  return saved;
}

async function operationSnapshot(page, operationId) {
  const operation = dataOf(await at(page, 'GET', `/production/operations/${operationId}`));
  expect(Array.isArray(operation.logs), 'operation detail includes persisted production log').toBe(true);
  return {
    status: operation.status,
    setupStart: operation.setup_start,
    setupEnd: operation.setup_end,
    actualStart: operation.actual_start,
    actualEnd: operation.actual_end,
    operatorId: operation.operator?.id ?? null,
    qtyCompleted: Number(operation.qty_completed),
    qtyScrapped: Number(operation.qty_scrapped),
    logs: operation.logs.map((log) => ({
      eventType: log.event_type,
      quantity: log.qty_value == null ? null : Number(log.qty_value),
      operatorId: log.operator_id,
    })),
  };
}

async function clickOperationAction(page, row, operationId, label, action, expectedStatus = 200) {
  const responsePromise = page.waitForResponse((response) =>
    response.url().endsWith(`/api/v1/production/operations/${operationId}/${action}`)
      && response.request().method() === 'POST');
  await row.getByRole('button', { name: label, exact: true }).click();
  const response = await responsePromise;
  const body = await response.json();
  expect(response.status(), JSON.stringify(body)).toBe(expectedStatus);
  return { status: response.status(), body, data: body.data ?? body };
}

async function runOperationViaUi(manager, qc, qcChecker, managerId, workOrderId, workOrderNumber, target, good, scrap) {
  await manager.goto(`/production/work-orders/${workOrderId}`);
  await manager.getByRole('tab', { name: 'Operations', exact: true }).click();
  const row = manager.getByRole('row').filter({ hasText: 'Injection moulding' });
  await expect(row).toBeVisible();

  const operations = dataOf(await at(manager, 'GET', `/production/work-orders/${workOrderId}/operations`));
  const operation = operations.find((item) => item.operation_name === 'Injection moulding');
  expect(operation, 'routed Injection moulding operation exists').toBeTruthy();
  const operationId = operation.id;
  const crew = dataOf(await at(manager, 'GET', `/production/work-orders/${workOrderId}/crew`));
  const recorderEmployeeId = crew.current?.recorder_employee_id;
  expect(recorderEmployeeId, 'operation has an assigned crew recorder').toBeTruthy();

  const initial = await operationSnapshot(manager, operationId);
  expect(initial.status).toBe('pending');
  expect(initial.logs).toHaveLength(0);

  await clickOperationAction(manager, row, operationId, 'Setup', 'start-setup');
  const setup = await operationSnapshot(manager, operationId);
  expect(setup.status).toBe('setup');
  expect(setup.setupStart).toBeTruthy();
  expect(setup.setupEnd).toBeNull();
  expect(setup.actualStart).toBeNull();
  expect(setup.operatorId).toBe(recorderEmployeeId);
  expect(setup.logs.map((log) => log.eventType).sort()).toEqual(['start_setup']);
  await expect(row.getByRole('button', { name: 'End setup', exact: true })).toBeVisible();

  await clickOperationAction(manager, row, operationId, 'End setup', 'end-setup');
  const setupEnded = await operationSnapshot(manager, operationId);
  expect(setupEnded.status).toBe('setup');
  expect(setupEnded.setupStart).toBe(setup.setupStart);
  expect(setupEnded.setupEnd).toBeTruthy();
  expect(setupEnded.actualStart).toBeNull();
  expect(setupEnded.logs.map((log) => log.eventType).sort()).toEqual(['end_setup', 'start_setup'].sort());

  await clickOperationAction(manager, row, operationId, 'Start', 'start');
  const started = await operationSnapshot(manager, operationId);
  expect(started.status).toBe('in_progress');
  expect(started.setupEnd).toBe(setupEnded.setupEnd);
  expect(started.actualStart).toBeTruthy();
  expect(started.actualEnd).toBeNull();
  expect(started.operatorId).toBe(recorderEmployeeId);
  expect(started.logs.map((log) => log.eventType).sort()).toEqual(['start_production', 'end_setup', 'start_setup'].sort());
  await expect(row.getByText('In Progress', { exact: true })).toBeVisible();
  await row.getByRole('button', { name: 'Output', exact: true }).click();
  const dialog = manager.getByRole('dialog');
  await dialog.getByLabel(/^Quantity\s?\*?$/).fill(String(good));
  await dialog.getByLabel('Scrap', { exact: true }).fill(String(scrap));
  if (scrap > 0) await dialog.getByLabel('Scrap reason', { exact: true }).fill('Reject recorded against the output defect line.');
  const outputPromise = manager.waitForResponse((response) =>
    response.url().endsWith(`/api/v1/production/operations/${operationId}/output`)
      && response.request().method() === 'POST');
  await dialog.getByRole('button', { name: 'Record output', exact: true }).click();
  const outputResponse = await outputPromise;
  const outputBody = await outputResponse.json();
  expect(outputResponse.status(), JSON.stringify(outputBody)).toBe(200);
  const afterOutput = await operationSnapshot(manager, operationId);
  expect(afterOutput.status).toBe('in_progress');
  expect(afterOutput.qtyCompleted).toBe(good);
  expect(afterOutput.qtyScrapped).toBe(scrap);
  expect(afterOutput.actualEnd).toBeNull();
  expect(afterOutput.logs.map((log) => log.eventType).sort()).toEqual(
    (scrap > 0
      ? ['record_scrap', 'record_output', 'start_production', 'end_setup', 'start_setup']
      : ['record_output', 'start_production', 'end_setup', 'start_setup']).sort(),
  );
  await expect(row.getByText(new RegExp(`${good} / ${target}`))).toBeVisible();

  const inspectionsBefore = dataOf(await at(
    qc,
    'GET',
    `/quality/inspections?entity_type=work_order&entity_id=${encodeURIComponent(workOrderId)}&per_page=100`,
  )).filter((inspection) => inspection.stage === 'in_process');
  const inspectionIdsBefore = new Set(inspectionsBefore.map((inspection) => inspection.id));
  await completeQcGatedOperation(
    manager, qc, qcChecker, managerId, workOrderId, workOrderNumber,
    operationId, afterOutput, inspectionIdsBefore,
  );
}

async function completeQcGatedOperation(manager, qc, qcChecker, managerId, workOrderId, workOrderNumber, operationId, beforeComplete, inspectionIdsBefore) {
  const qcGateMessage = /(?:Required QC .* must pass before this operation completes|waiting for in-process QC .* approved final result)/i;
  const row = manager.getByRole('row').filter({ hasText: 'Injection moulding' });
  const deniedUi = await clickOperationAction(manager, row, operationId, 'Complete', 'complete', 409);
  expect(deniedUi.body.message).toMatch(qcGateMessage);
  await expect(manager.getByText(qcGateMessage)).toBeVisible();

  const afterUiDenial = await operationSnapshot(manager, operationId);
  expect(afterUiDenial).toEqual(beforeComplete);
  const inspectionsAfterUi = dataOf(await at(
    qc,
    'GET',
    `/quality/inspections?entity_type=work_order&entity_id=${encodeURIComponent(workOrderId)}&per_page=100`,
  )).filter((inspection) => inspection.stage === 'in_process');
  const created = inspectionsAfterUi.filter((inspection) => !inspectionIdsBefore.has(inspection.id));
  expect(created).toHaveLength(1);
  const inspection = dataOf(await at(qc, 'GET', `/quality/inspections/${created[0].id}`));
  expect(inspection.status).toMatch(/^(draft|in_progress)$/);
  expect(inspection.batch_quantity).toBe(beforeComplete.qtyCompleted);
  const attachedToThisOperation = psql(`SELECT count(*) FROM inspections i
    JOIN work_orders w ON w.id = i.entity_id
    JOIN wo_operations op ON op.work_order_id = w.id
    WHERE w.wo_number = '${workOrderNumber}'
      AND op.operation_name = 'Injection moulding'
      AND i.inspection_number = '${inspection.inspection_number}'
      AND i.wo_operation_id = op.id`, DB);
  expect(attachedToThisOperation).toBe('1');

  const deniedApi = await at(manager, 'POST', `/production/operations/${operationId}/complete`, undefined, 409);
  expect(deniedApi.body.message).toMatch(qcGateMessage);
  expect(await operationSnapshot(manager, operationId)).toEqual(beforeComplete);
  const inspectionsAfterRetry = dataOf(await at(
    qc,
    'GET',
    `/quality/inspections?entity_type=work_order&entity_id=${encodeURIComponent(workOrderId)}&per_page=100`,
  )).filter((row) => row.stage === 'in_process');
  expect(inspectionsAfterRetry.filter((row) => !inspectionIdsBefore.has(row.id)).map((row) => row.id)).toEqual([created[0].id]);
  const operationInspectionCount = psql(`SELECT count(*) FROM inspections i
    JOIN work_orders w ON w.id = i.entity_id
    JOIN wo_operations op ON op.work_order_id = w.id
    WHERE w.wo_number = '${workOrderNumber}' AND op.operation_name = 'Injection moulding'
      AND i.wo_operation_id = op.id AND i.status <> 'cancelled'`, DB);
  expect(operationInspectionCount).toBe('1');
  passed('Operation completion creates one linked QC record, rejects UI and API retries without advancing the operation', {
    operation: operationId,
    deniedUi: deniedUi.status,
    deniedApi: deniedApi.status,
    inspection: inspection.inspection_number,
    inspectionStatus: inspection.status,
    operationStatus: afterUiDenial.status,
    operationLogs: afterUiDenial.logs,
  });

  const resolved = await measureReviewAll(qc, qcChecker, manager, managerId, workOrderId, 'in_process');
  expect(resolved.some((row) => row.id === inspection.id && row.final_status === 'passed')).toBe(true);
  const savedInspection = dataOf(await at(qc, 'GET', `/quality/inspections/${inspection.id}`));
  expect(savedInspection.status).toBe('passed');
  expect(psql(`SELECT status::text FROM inspections WHERE inspection_number = '${inspection.inspection_number}'`, DB)).toBe('passed');
  expect(await operationSnapshot(manager, operationId)).toEqual(beforeComplete);
  passed('Quality resolves required operation inspection before completion; operation remains in progress until Production retries', {
    inspection: savedInspection.inspection_number,
    inspectionStatus: savedInspection.status,
    operationStatus: beforeComplete.status,
    result: resolved.find((row) => row.id === inspection.id),
  });

  await manager.goto(`/production/work-orders/${workOrderId}`);
  await manager.getByRole('tab', { name: 'Operations', exact: true }).click();
  const refreshedRow = manager.getByRole('row').filter({ hasText: 'Injection moulding' });
  const completed = await clickOperationAction(manager, refreshedRow, operationId, 'Complete', 'complete');
  expect(completed.data.status).toBe('completed');
  await expect(refreshedRow.getByText('Completed', { exact: true })).toBeVisible();
  const afterCompletion = await operationSnapshot(manager, operationId);
  expect(afterCompletion.status).toBe('completed');
  expect(afterCompletion.actualEnd).toBeTruthy();
  expect(afterCompletion.qtyCompleted).toBe(beforeComplete.qtyCompleted);
  expect(afterCompletion.qtyScrapped).toBe(beforeComplete.qtyScrapped);
  expect(afterCompletion.logs.map((log) => log.eventType).sort()).toEqual([
    'end_production', ...beforeComplete.logs.map((log) => log.eventType),
  ].sort());
  await expect(refreshedRow.getByRole('button', { name: 'Complete', exact: true })).toHaveCount(0);

  const repeatComplete = await at(manager, 'POST', `/production/operations/${operationId}/complete`, undefined, 409);
  expect(repeatComplete.body.message).toMatch(/complete|in.progress/i);
  expect(await operationSnapshot(manager, operationId)).toEqual(afterCompletion);
  passed('A QC-passed operation completes once through the UI; repeated API completion is rejected without duplicate logs', {
    operation: operationId,
    status: afterCompletion.status,
    actualEnd: afterCompletion.actualEnd,
    logCount: afterCompletion.logs.length,
    repeatStatus: repeatComplete.status,
  });
}

async function recordInspectionViaUi(inspector, inspectionData) {
  const inspectionId = inspectionData.id;
  const lotChecklist = inspectionData.inspection_mode === 'lot_checklist';
  await inspector.goto(`/quality/inspections/${inspectionId}`);
  const measurements = inspectionData.measurements ?? [];
  const numericRows = measurements.filter((row) => row.is_critical
    && (row.tolerance_min !== null || row.tolerance_max !== null));
  const numericInputs = inspector.locator('input[type="number"][step="0.0001"]');
  await expect(numericInputs).toHaveCount(numericRows.length);
  const measured = [];
  for (let index = 0; index < numericRows.length; index++) {
    const row = numericRows[index];
    const label = `${row.parameter_name}${lotChecklist ? ', piece' : ' sample'} ${row.sample_index}${row.unit_of_measure ? ` (${row.unit_of_measure})` : ''}`;
    const value = row.nominal_value ?? row.tolerance_min ?? row.tolerance_max;
    expect(value, `${label} must have an approved nominal or tolerance`).not.toBeNull();
    await numericInputs.nth(index).fill(String(value));
    measured.push({ id: row.id, value: Number(value) });
  }

  if (lotChecklist) {
    await inspector.getByRole('spinbutton', { name: 'Inspected pieces' }).fill(String(inspectionData.sample_size));
    await inspector.getByRole('spinbutton', { name: 'Defective pieces found' }).fill('0');
    await inspector.getByRole('checkbox', { name: 'I confirm all required checks on the planned sample were completed' }).check();
  } else if (measured.length > 0) {
    const savePromise = inspector.waitForResponse((response) =>
      response.url().endsWith(`/api/v1/quality/inspections/${inspectionId}/measurements`)
        && response.request().method() === 'PATCH');
    await inspector.getByRole('button', { name: 'Save measurements', exact: true }).click();
    const response = await savePromise;
    const body = await response.json();
    expect(response.status(), JSON.stringify(body)).toBe(200);
    const saved = dataOf(await at(inspector, 'GET', `/quality/inspections/${inspectionId}`));
    for (const row of measured) {
      expect(Number(saved.measurements.find((measurement) => measurement.id === row.id)?.measured_value)).toBe(row.value);
    }
  }

  for (const parameterName of [...new Set(measurements.map((row) => row.parameter_name))]) {
    await inspector.getByRole('button', { name: `PASS ${parameterName}`, exact: true }).click();
  }
  const submitLabel = lotChecklist ? 'Submit result' : 'Submit inspection';
  const resultEndpoint = lotChecklist ? 'batch-result' : 'item-results';
  const submitPromise = inspector.waitForResponse((response) =>
    response.url().endsWith(`/api/v1/quality/inspections/${inspectionId}/${resultEndpoint}`)
      && response.request().method() === 'POST');
  await inspector.getByRole('button', { name: submitLabel, exact: true }).click();
  const response = await submitPromise;
  const body = await response.json();
  expect(response.status(), JSON.stringify(body)).toBe(200);
  return body.data ?? body;
}

async function reviewInspectionViaUi(reviewer, inspectionId) {
  await reviewer.goto(`/quality/inspections/${inspectionId}`);
  await expect(reviewer.getByRole('button', { name: 'Approve pass', exact: true })).toBeVisible();
  await reviewer.getByRole('button', { name: 'Approve pass', exact: true }).click();
  const dialog = reviewer.getByRole('dialog');
  const responsePromise = reviewer.waitForResponse((response) =>
    response.url().endsWith(`/api/v1/quality/inspections/${inspectionId}/review`)
      && response.request().method() === 'PATCH');
  await dialog.getByRole('button', { name: 'Approve pass', exact: true }).click();
  const response = await responsePromise;
  const body = await response.json();
  expect(response.status(), JSON.stringify(body)).toBe(200);
  await expect(dialog).toHaveCount(0);
  return body.data ?? body;
}

async function measureReviewAll(qc, qcChecker, manager, managerId, workOrderId, stage, verifyManagerSelfReview = false) {
  const list = await at(qc, 'GET', `/quality/inspections?entity_type=work_order&entity_id=${encodeURIComponent(workOrderId)}&per_page=100`);
  const inspections = dataOf(list).filter((row) => row.stage === stage);
  expect(inspections.length, `expected ${stage} inspection(s) for ${workOrderId}`).toBeGreaterThan(0);
  const reviewed = [];
  for (const inspection of inspections) {
    let inspectionData = dataOf(await at(qc, 'GET', `/quality/inspections/${inspection.id}`));
    expect(inspectionData.measurements?.length ?? 0, `${stage} measurement rows`).toBeGreaterThan(0);
    let completion = inspectionData;
    if (['draft', 'in_progress'].includes(inspectionData.status)) {
      completion = await recordInspectionViaUi(qc, inspectionData);
      inspectionData = dataOf(await at(qc, 'GET', `/quality/inspections/${inspection.id}`));
    }
    let finalInspection = completion;
    if (completion.status === 'awaiting_review') {
      if (verifyManagerSelfReview && inspectionData.inspector?.id === managerId) {
        const selfReview = await at(manager, 'PATCH', `/quality/inspections/${inspection.id}/review`, {
          decision: 'passed',
          remarks: `Attempted maker self-review of ${stage} inspection; Quality must reject this.`,
        }, 403);
        expect(selfReview.body.message).toMatch(/cannot review.*performed/i);
        expect(dataOf(await at(qcChecker, 'GET', `/quality/inspections/${inspection.id}`)).status).toBe('awaiting_review');
        passed('Quality prevents the Production Manager from reviewing an inspection they performed', {
          inspection: inspection.id,
          inspector: inspectionData.inspector.id,
          reviewerAttempt: managerId,
          status: selfReview.status,
          message: selfReview.body.message,
        });
      }
      finalInspection = await reviewInspectionViaUi(qcChecker, inspection.id);
    }
    if (stage === 'outgoing') {
      expect(completion.status, 'every output-bound outgoing lot waits for checker review').toBe('awaiting_review');
      finalInspection = dataOf(await at(qcChecker, 'GET', `/quality/inspections/${inspection.id}`));
    }
    finalInspection = dataOf(await at(qcChecker, 'GET', `/quality/inspections/${inspection.id}`));
    expect(finalInspection.status, `${stage} inspection disposition persisted after UI action`).toBe('passed');
    expect(finalInspection.inspector?.id, `${stage} result recorder`).toBeTruthy();
    if (finalInspection.reviewed_at) {
      expect(finalInspection.reviewer?.id).toBeTruthy();
      expect(finalInspection.inspector?.id).toBeTruthy();
      expect(finalInspection.reviewer.id).not.toBe(finalInspection.inspector.id);
    }
    if (stage === 'outgoing') {
      expect(finalInspection.reviewed_at).toBeTruthy();
      expect(finalInspection.accepted_quantity).toBe(finalInspection.batch_quantity);
      expect(finalInspection.work_order_output?.id).toBe(inspectionData.work_order_output?.id);
      expect(finalInspection.work_order_output?.id).toBeTruthy();

      const currentOutgoing = dataOf(await at(
        qc,
        'GET',
        `/quality/inspections?entity_type=work_order&entity_id=${encodeURIComponent(workOrderId)}&per_page=100`,
      )).filter((row) => row.stage === 'outgoing');
      const allOutputsPassed = currentOutgoing.length > 0 && currentOutgoing.every((row) => row.status === 'passed');
      const workOrderAfterReview = dataOf(await at(manager, 'GET', `/production/work-orders/${workOrderId}`));
      expect(workOrderAfterReview.status).toBe(allOutputsPassed ? 'closed' : 'completed');
      if (allOutputsPassed) {
        expect(workOrderAfterReview.quality_close_blocker).toBeNull();
      } else {
        expect(typeof workOrderAfterReview.quality_close_blocker).toBe('string');
        expect(workOrderAfterReview.quality_close_blocker).not.toBe('');
      }
      const closedTransitionCount = Number(psql(`SELECT count(*) FROM audit_logs
        WHERE model_type LIKE '%WorkOrder%'
          AND model_id = (SELECT entity_id FROM inspections WHERE inspection_number = '${finalInspection.inspection_number}')
          AND old_values->>'status' = 'completed' AND new_values->>'status' = 'closed'`, DB));
      expect(closedTransitionCount).toBe(allOutputsPassed ? 1 : 0);
      passed('Each output-bound QC review leaves the WO completed while another lot is open, then auto-closes it after the last lot passes', {
        inspection: finalInspection.inspection_number,
        disposition: finalInspection.status,
        reviewedBy: finalInspection.reviewer?.id,
        outgoingLotsPassed: currentOutgoing.filter((row) => row.status === 'passed').length,
        outgoingLotsTotal: currentOutgoing.length,
        workOrderStatus: workOrderAfterReview.status,
        qualityCloseBlocker: workOrderAfterReview.quality_close_blocker,
        closeAuditCount: closedTransitionCount,
      });
    }
    reviewed.push({
      id: inspection.id,
      stage: inspection.stage,
      output_batch: inspectionData.work_order_output?.batch_code ?? null,
      batch_quantity: finalInspection.batch_quantity,
      accepted_quantity: finalInspection.accepted_quantity,
      final_status: finalInspection.status,
      inspector: finalInspection.inspector?.id ?? null,
      reviewer: finalInspection.reviewer?.id ?? null,
      reviewed_at: finalInspection.reviewed_at ?? null,
    });
  }
  const independentlyReviewed = reviewed.filter((row) => row.reviewer).length;
  report.handoffs.push({
    from: 'Quality Inspector UI',
    to: independentlyReviewed > 0
      ? 'Independent Quality checker UI'
      : 'No separate checker step required for this stage',
    stage,
    count: reviewed.length,
    independently_reviewed: independentlyReviewed,
    evidence: reviewed,
  });
  passed(`Quality UI measurements and ${reviewed.length} ${stage} disposition(s)`, reviewed);
  return reviewed;
}

async function assertCloseBlockedBeforeOutgoingQc(manager, workOrderId, workOrderNumber) {
  await manager.goto(`/production/work-orders/${workOrderId}`);
  const before = dataOf(await at(manager, 'GET', `/production/work-orders/${workOrderId}`));
  expect(before.status).toBe('completed');
  expect(before.quality_close_blocker).toBeTruthy();
  await expect(manager.getByText(before.quality_close_blocker, { exact: true })).toBeVisible();
  await expect(manager.getByRole('button', { name: 'Close', exact: true })).toHaveCount(0);
  const deniedClose = await at(manager, 'POST', `/production/work-orders/${workOrderId}/close`, undefined, 422);
  expect(deniedClose.body.message).toBe(before.quality_close_blocker);
  const after = dataOf(await at(manager, 'GET', `/production/work-orders/${workOrderId}`));
  expect(after.status).toBe('completed');
  expect(after.quality_close_blocker).toBe(before.quality_close_blocker);
  const closeAuditCount = Number(psql(`SELECT count(*) FROM audit_logs
    WHERE model_type LIKE '%WorkOrder%'
      AND model_id = (SELECT id FROM work_orders WHERE wo_number = '${workOrderNumber}')
      AND old_values->>'status' = 'completed' AND new_values->>'status' = 'closed'`, DB));
  expect(closeAuditCount).toBe(0);
  passed('PPC cannot close a completed work order while outgoing Quality remains unresolved; UI and API block without a status audit', {
    status: after.status,
    blocker: after.quality_close_blocker,
    apiStatus: deniedClose.status,
    closeAuditCount,
  });
}

async function assertAutoClosedAfterQuality(manager, workOrderId, workOrderNumber, expectedActorEmail) {
  await manager.goto(`/production/work-orders/${workOrderId}`);
  const closed = dataOf(await at(manager, 'GET', `/production/work-orders/${workOrderId}`));
  expect(closed.status).toBe('closed');
  expect(closed.quality_close_blocker).toBeNull();
  await expect(manager.locator('h1').getByText('Closed', { exact: true })).toBeVisible();
  await expect(manager.getByRole('button', { name: 'Close', exact: true })).toHaveCount(0);
  const closeAuditCount = Number(psql(`SELECT count(*) FROM audit_logs
    WHERE model_type LIKE '%WorkOrder%'
      AND model_id = (SELECT id FROM work_orders WHERE wo_number = '${workOrderNumber}')
      AND old_values->>'status' = 'completed' AND new_values->>'status' = 'closed'`, DB));
  expect(closeAuditCount).toBe(1);
  const closeActor = psql(`SELECT u.email FROM audit_logs a
    JOIN users u ON u.id = a.user_id
    WHERE a.model_type LIKE '%WorkOrder%'
      AND a.model_id = (SELECT id FROM work_orders WHERE wo_number = '${workOrderNumber}')
      AND a.old_values->>'status' = 'completed' AND a.new_values->>'status' = 'closed'`, DB);
  expect(closeActor).toBe(expectedActorEmail);
  const closeChainEvents = Number(psql(`SELECT count(*) FROM chain_step_runs
    WHERE chain = 'o2c' AND entity_type = 'work_order'
      AND entity_id = (SELECT id FROM work_orders WHERE wo_number = '${workOrderNumber}')
      AND step = 'closed' AND status = 'published'`, DB));
  expect(closeChainEvents).toBe(2);
  const repeatedClose = await at(manager, 'POST', `/production/work-orders/${workOrderId}/close`, undefined, 409);
  expect(repeatedClose.body.message).toMatch(/closed|cannot/i);
  expect(dataOf(await at(manager, 'GET', `/production/work-orders/${workOrderId}`)).status).toBe('closed');
  expect(Number(psql(`SELECT count(*) FROM audit_logs
    WHERE model_type LIKE '%WorkOrder%'
      AND model_id = (SELECT id FROM work_orders WHERE wo_number = '${workOrderNumber}')
      AND old_values->>'status' = 'completed' AND new_values->>'status' = 'closed'`, DB))).toBe(1);
  passed('The final output-bound Quality approval auto-closes the WO once; the action is audited to the Quality checker and repeat close is rejected', {
    status: closed.status,
    auditedActor: closeActor,
    closeAuditCount,
    publishedCloseChainEvents: closeChainEvents,
    repeatedCloseStatus: repeatedClose.status,
  });
}

async function main() {
  if (BOOTSTRAP) viteServer = await startVite();
  expect(fixture.database).toMatch(/^ogami_test_production_workorders_browser_/);
  const mobileManager = await login('production@ogami.test', { width: 390, height: 844 });
  const roleOptions = await at(mobileManager, 'GET', '/production/work-orders/form-options');
  expect(dataOf(roleOptions).products.some((product) => product.id === fixture.product_id)).toBe(true);
  const deniedCrm = await request(mobileManager, 'GET', '/crm/products?per_page=1');
  const deniedShifts = await request(mobileManager, 'GET', '/attendance/shifts?per_page=200');
  expect(deniedCrm.status).toBe(403);
  expect(deniedShifts.status).toBe(403);
  passed('Production Manager can use scoped product/machine/mold/shift lookups without CRM or Attendance permission', {
    scopedOptionsStatus: roleOptions.status,
    products: dataOf(roleOptions).products.length,
    machines: dataOf(roleOptions).machines.length,
    molds: dataOf(roleOptions).molds.length,
    shifts: dataOf(roleOptions).shifts.length,
    unrelatedCrmStatus: deniedCrm.status,
    unrelatedAttendanceStatus: deniedShifts.status,
  });

  let formOptionsFailures = 2;
  let formOptionsAttempts = 0;
  await mobileManager.route('**/api/v1/production/work-orders/form-options', async (route) => {
    formOptionsAttempts++;
    if (formOptionsFailures > 0) {
      formOptionsFailures--;
      await route.fulfill({ status: 503, contentType: 'application/json', body: '{"message":"audit injected lookup failure"}' });
      return;
    }
    await route.continue();
  });
  await mobileManager.goto('/production/work-orders/create');
  await expect(mobileManager.getByRole('heading', { name: 'New work order' })).toBeVisible();
  await expect(mobileManager.getByText('Could not load work-order choices. Your current entries are unchanged.', { exact: true })).toBeVisible();
  await mobileManager.getByLabel(/^Quantity target\s?\*?$/).fill('1');
  await screenshot(mobileManager, 'mobile-create-options-retry-state.png');
  await mobileManager.unroute('**/api/v1/production/work-orders/form-options');
  await mobileManager.getByRole('button', { name: 'Retry lookups', exact: true }).click();
  const productPicker = mobileManager.getByRole('combobox', { name: 'Product' });
  const productOption = mobileManager.getByRole('option', { name: new RegExp(fixture.product_code) });
  await productPicker.focus();
  await expect(productOption).toBeVisible();
  expect(await mobileManager.getByLabel(/^Quantity target\s?\*?$/).inputValue()).toBe('1');
  expect(formOptionsFailures).toBe(0);
  await productPicker.press('Escape');
  passed('Form-options failure is visible after automatic retries, manual Retry reloads live lookups and preserves typed quantity', { injectedFailureStatus: 503, failedRequests: 2, totalAttempts: formOptionsAttempts });
  await productPicker.click();
  await expect(productOption).toBeVisible();
  await productPicker.press('Escape');
  await assertMobileFit(mobileManager, 'new work-order form');
  const localDateEvidence = await mobileManager.getByLabel(/^Planned start\s?\*?$/).evaluate((input) => {
    const [date, time] = (input.value || '').split('T');
    const [year, month, day] = date.split('-').map(Number);
    const [hour, minute] = time.split(':').map(Number);
    const value = new Date(year, month - 1, day, hour, minute);
    return { field: input.value, timezone: Intl.DateTimeFormat().resolvedOptions().timeZone, minuteDelta: Math.abs(value.getTime() - Date.now()) / 60_000 };
  });
  expect(localDateEvidence.minuteDelta).toBeLessThan(2);
  await productPicker.click();
  await productOption.click();
  await mobileManager.getByLabel(/^Quantity target\s?\*?$/).fill('1');
  const dateFields = await mobileManager.locator('input[type="datetime-local"]').evaluateAll((nodes) => nodes.map((node) => node.value));
  const plusHour = (value) => {
    const date = new Date(`${value}:00`);
    date.setHours(date.getHours() + 1);
    return new Date(date.getTime() - date.getTimezoneOffset() * 60_000).toISOString().slice(0, 16);
  };
  await mobileManager.getByLabel(/^Planned start\s?\*?$/).fill(plusHour(dateFields[0]));
  await mobileManager.getByLabel(/^Planned end\s?\*?$/).fill(plusHour(plusHour(dateFields[0])));
  const createdResponsePromise = mobileManager.waitForResponse((response) =>
    response.url().endsWith('/api/v1/production/work-orders') && response.request().method() === 'POST');
  await mobileManager.getByRole('button', { name: 'Create work order', exact: true }).click();
  const createdResponse = await createdResponsePromise;
  const createdBody = await createdResponse.json();
  expect(createdResponse.status(), JSON.stringify(createdBody)).toBe(201);
  await expect(mobileManager).toHaveURL(/\/production\/work-orders\/[A-Za-z0-9]+$/);
  await expect(mobileManager.locator('.overflow-x-auto table').first()).toBeVisible();
  await assertMobileFit(mobileManager, 'created work-order detail', true);
  await screenshot(mobileManager, 'mobile-work-order-created.png');
  passed('Production Manager creates a planned work order from the production UI; wall-clock datetime-local default is local', {
    status: createdBody.data.status,
    wo_number: createdBody.data.wo_number,
    planned_start_field: localDateEvidence.field,
    timezone: localDateEvidence.timezone,
  });

  const ppc = await login('ppc@ogami.test');
  const ppcOptions = await at(ppc, 'GET', '/production/work-orders/form-options');
  expect(dataOf(ppcOptions).products.some((product) => product.id === fixture.product_id)).toBe(true);
  const ppcAttendance = await request(ppc, 'GET', '/attendance/shifts?per_page=200');
  expect(ppcAttendance.status).toBe(403);
  passed('PPC role has scoped Production form lookups and no Attendance shift-list permission', { options: ppcOptions.status, attendance: ppcAttendance.status });
  await ppc.context().close();

  const manager = await login('production@ogami.test');
  const warehouse = await login('warehouse@ogami.test');
  const qc = await login('qc@ogami.test');
  const qcChecker = await login(fixture.qc_checker_email);
  const maintenance = await login('maintenance@ogami.test');
  const outputRecorder = await login(fixture.output_operator_email);
  const mobileOutputRecorder = await login(fixture.output_operator_email, { width: 390, height: 844 });
  const managerId = dataOf(await at(manager, 'GET', '/auth/user')).id;
  expect(managerId).toBeTruthy();
  expect(dataOf(await at(outputRecorder, 'GET', '/production/my-work-orders?per_page=20'))).toHaveLength(0);
  const employeeProductionList = await request(outputRecorder, 'GET', '/production/work-orders?per_page=20');
  expect(employeeProductionList.status).toBe(403);
  passed('Production employee sees no unassigned work before crew allocation and cannot open the privileged production list', {
    assigned: 0,
    privilegedList: employeeProductionList.status,
  });

  // Confirm and reserve the Recovery work order through the actual detail modal.
  const confirmedRecovery = await confirmViaUi(
    manager, warehouse, fixture.recovery_work_order_id, fixture.recovery_work_order_number, 2,
  );
  const confirmedMain = await confirmViaUi(
    manager, warehouse, fixture.main_work_order_id, fixture.main_work_order_number, 7,
  );
  expect(confirmedRecovery.status).toBe('confirmed');
  expect(confirmedMain.status).toBe('confirmed');
  expect(Number(confirmedRecovery.quantity_target) + Number(confirmedMain.quantity_target)).toBe(7);
  const rawStock = await stockAt(warehouse, fixture.raw_item_id, fixture.raw_location_id);
  expect(Number(rawStock.quantity)).toBe(8);
  expect(Number(rawStock.reserved_quantity)).toBe(7);
  expect(Number(rawStock.available)).toBe(1);
  passed('Confirmation reserves the BOM quantity across the two linked orders', {
    target: confirmedRecovery.quantity_target,
    quantity: rawStock.quantity,
    reserved: rawStock.reserved_quantity,
    available: rawStock.available,
  });

  const recoveryIssue = await createIssueInUi(warehouse, fixture.recovery_work_order_id, 2, fixture.material_lot_number, fixture.recovery_work_order_number, 'reservation');
  const afterRecoveryIssue = await stockAt(warehouse, fixture.raw_item_id, fixture.raw_location_id);
  expect(Number(afterRecoveryIssue.quantity)).toBe(6);
  expect(Number(afterRecoveryIssue.reserved_quantity)).toBe(5);
  await at(warehouse, 'DELETE', `/inventory/material-issues/${recoveryIssue.slip.id}`);
  const afterPreOutputCancel = await stockAt(warehouse, fixture.raw_item_id, fixture.raw_location_id);
  expect(Number(afterPreOutputCancel.quantity)).toBe(8);
  expect(Number(afterPreOutputCancel.reserved_quantity)).toBe(5);
  expect(Number(afterPreOutputCancel.available)).toBe(3);
  const shortStart = await startViaUi(manager, fixture.recovery_work_order_id, 422);
  expect(shortStart.body.message).toMatch(/material|issue|coverage|short/i);
  await manager.getByRole('dialog').getByRole('button', { name: 'Cancel', exact: true }).click();
  passed('Pre-output Warehouse cancellation returns unused stock without reopening reservation; Production start blocks the resulting short coverage', {
    reservedLinkedSlip: recoveryIssue.slip.slip_number,
    afterIssue: { quantity: afterRecoveryIssue.quantity, reserved: afterRecoveryIssue.reserved_quantity },
    afterCancel: { quantity: afterPreOutputCancel.quantity, reserved: afterPreOutputCancel.reserved_quantity, available: afterPreOutputCancel.available },
    blockedStart: shortStart.response.status,
    blockedReason: shortStart.body.message,
  });

  const recoveryReissue = await createIssueInUi(warehouse, fixture.recovery_work_order_id, 2, fixture.material_lot_number, fixture.recovery_work_order_number, 'general', 'keyboard');
  expect(recoveryReissue.slip.items?.[0]?.material_reservation_id ?? null).toBeNull();
  const beforeOutsideSlot = await startState(manager, warehouse, fixture.recovery_work_order_id, fixture.recovery_work_order_number);
  const recoveryCrewBeforeStart = dataOf(await at(manager, 'GET', `/production/work-orders/${fixture.recovery_work_order_id}/crew`));
  const directOutsideSlot = await request(manager, 'POST', `/production/work-orders/${fixture.recovery_work_order_id}/start`, {
    expected_crew_id: recoveryCrewBeforeStart.current.id,
    presence_confirmed_employee_ids: [],
  });
  expect(directOutsideSlot.status, JSON.stringify(directOutsideSlot.body)).toBe(422);
  expect(directOutsideSlot.body.message).toMatch(/outside the approved production slot/i);
  expect(await startState(manager, warehouse, fixture.recovery_work_order_id, fixture.recovery_work_order_number)).toEqual(beforeOutsideSlot);
  const uiOutsideSlot = await startViaUi(manager, fixture.recovery_work_order_id, 422);
  expect(uiOutsideSlot.body.message).toMatch(/outside the approved production slot/i);
  await expect(manager.getByText(/Current time is outside the approved production slot/i)).toBeVisible();
  await manager.getByRole('dialog').getByRole('button', { name: 'Cancel', exact: true }).click();
  expect(await startState(manager, warehouse, fixture.recovery_work_order_id, fixture.recovery_work_order_number)).toEqual(beforeOutsideSlot);
  passed('Future-slot start is rejected by the API and UI without changing work order, stock, equipment, or chain state', {
    directStatus: directOutsideSlot.status,
    directMessage: directOutsideSlot.body.message,
    uiStatus: uiOutsideSlot.response.status(),
    unchanged: beforeOutsideSlot,
  });

  const reviewedRecoverySlot = await reviewSlotViaUi(
    manager, fixture.recovery_work_order_id, fixture.recovery_work_order_number,
  );
  await startViaUi(manager, fixture.recovery_work_order_id, 200);
  const recoveryDetail = dataOf(await at(manager, 'GET', `/production/work-orders/${fixture.recovery_work_order_id}`));
  expect(recoveryDetail.status).toBe('in_progress');
  expect(recoveryDetail.actual_start).toBeTruthy();
  expect(recoveryDetail.material_lot_references.some((row) => row.material_lot_number === fixture.material_lot_number)).toBe(true);
  expect(Number(recoveryDetail.materials[0].actual_quantity_issued)).toBe(2);
  expect(Number(recoveryDetail.material_cost_summary.actual_cost)).toBe(8);
  const recoveryStartedCrew = dataOf(await at(manager, 'GET', `/production/work-orders/${fixture.recovery_work_order_id}/crew`));
  expect(recoveryStartedCrew.schedule.id).toBe(reviewedRecoverySlot.id);
  expect(psql(`SELECT m.status || ':' || w.wo_number FROM machines m JOIN work_orders w ON w.id = m.current_work_order_id WHERE m.machine_code = '${fixture.machine_code}'`, fixture.database)).toBe(`running:${fixture.recovery_work_order_number}`);
  expect(psql(`SELECT m.status || ':' || w.wo_number FROM molds m JOIN work_orders w ON w.id = m.current_work_order_id WHERE m.mold_code = '${fixture.mold_code}'`, fixture.database)).toBe(`in_use:${fixture.recovery_work_order_number}`);

  // Exercise breakdown pause, system-created corrective WO and maintenance role recovery.
  await manager.getByRole('button', { name: 'Pause', exact: true }).click();
  await manager.getByLabel(/^Downtime category\s?\*?$/).selectOption('breakdown');
  await manager.getByLabel(/^Reason\s?\*?$/).fill('Audit: machine breakdown recovery.');
  const pausePromise = manager.waitForResponse((response) =>
    response.url().endsWith(`/api/v1/production/work-orders/${fixture.recovery_work_order_id}/pause`));
  await manager.getByRole('dialog').getByRole('button', { name: 'Pause work order', exact: true }).click();
  const pauseResponse = await pausePromise;
  expect(pauseResponse.status()).toBe(200);
  await expect(manager.getByText('Paused', { exact: true })).toBeVisible();
  const pausedRecovery = dataOf(await at(manager, 'GET', `/production/work-orders/${fixture.recovery_work_order_id}`));
  expect(pausedRecovery.status).toBe('paused');
  expect(pausedRecovery.pause_reason).toBe('Audit: machine breakdown recovery.');
  const pausedMachineState = psql(`SELECT m.status || ':' || COALESCE(w.wo_number, '') FROM machines m
    LEFT JOIN work_orders w ON w.id = m.current_work_order_id WHERE m.machine_code = '${fixture.machine_code}'`, fixture.database);
  const pausedMoldState = psql(`SELECT m.status || ':' || COALESCE(w.wo_number, '') FROM molds m
    LEFT JOIN work_orders w ON w.id = m.current_work_order_id WHERE m.mold_code = '${fixture.mold_code}'`, fixture.database);
  expect(pausedMachineState).toBe(`breakdown:${fixture.recovery_work_order_number}`);
  expect(pausedMoldState).toBe(`in_use:${fixture.recovery_work_order_number}`);
  const maintenanceList = dataOf(await at(maintenance, 'GET', '/maintenance/work-orders?per_page=100'));
  const corrective = maintenanceList.find((row) => row.maintainable?.id === fixture.machine_id && row.status === 'open');
  expect(corrective, 'system-created machine corrective work order').toBeTruthy();
  const correctiveBeforeStart = dataOf(await at(maintenance, 'GET', `/maintenance/work-orders/${corrective.id}`));
  expect(correctiveBeforeStart.status).toBe('open');
  expect(correctiveBeforeStart.started_at).toBeNull();
  const blockedMaintenanceStart = await maintenanceStartViaUi(maintenance, corrective.id, 422);
  expect(blockedMaintenanceStart.body.message).toMatch(/assigned to active production/i);
  const correctiveAfterBlockedUi = dataOf(await at(maintenance, 'GET', `/maintenance/work-orders/${corrective.id}`));
  expect(correctiveAfterBlockedUi.status).toBe('open');
  expect(correctiveAfterBlockedUi.started_at).toBeNull();
  expect(psql(`SELECT m.status || ':' || COALESCE(w.wo_number, '') FROM machines m
    LEFT JOIN work_orders w ON w.id = m.current_work_order_id WHERE m.machine_code = '${fixture.machine_code}'`, fixture.database)).toBe(pausedMachineState);
  const blockedMaintenanceStartApi = await request(maintenance, 'PATCH', `/maintenance/work-orders/${corrective.id}/start`);
  expect(blockedMaintenanceStartApi.status).toBe(422);
  expect(blockedMaintenanceStartApi.body.message).toMatch(/assigned to active production/i);
  expect(dataOf(await at(maintenance, 'GET', `/maintenance/work-orders/${corrective.id}`))).toMatchObject({
    status: 'open', started_at: null,
  });
  expect(psql(`SELECT m.status || ':' || COALESCE(w.wo_number, '') FROM machines m
    LEFT JOIN work_orders w ON w.id = m.current_work_order_id WHERE m.machine_code = '${fixture.machine_code}'`, fixture.database)).toBe(pausedMachineState);
  passed('Maintenance Start is denied in UI and API while paused Production still owns equipment; no MWO or machine state changes', {
    uiStatus: blockedMaintenanceStart.response.status(),
    apiStatus: blockedMaintenanceStartApi.status,
    maintenanceStatus: correctiveAfterBlockedUi.status,
    machine: pausedMachineState,
    mold: pausedMoldState,
  });

  const releaseReason = 'Release equipment to complete the machine breakdown work order.';
  const releasedRecovery = await releasePausedResourcesViaUi(manager, fixture.recovery_work_order_id, releaseReason);
  expect(releasedRecovery.status).toBe('paused');
  expect(releasedRecovery.production_released_at).toBeTruthy();
  expect(releasedRecovery.pause_reason).toBe(releaseReason);
  expect(dataOf(await at(manager, 'GET', `/production/work-orders/${fixture.recovery_work_order_id}/crew`)).schedule).toBeNull();
  const releasedMachineState = psql(`SELECT m.status || ':' || COALESCE(w.wo_number, '') FROM machines m
    LEFT JOIN work_orders w ON w.id = m.current_work_order_id WHERE m.machine_code = '${fixture.machine_code}'`, fixture.database);
  const releasedMoldState = psql(`SELECT m.status || ':' || COALESCE(w.wo_number, '') FROM molds m
    LEFT JOIN work_orders w ON w.id = m.current_work_order_id WHERE m.mold_code = '${fixture.mold_code}'`, fixture.database);
  expect(releasedMachineState).toBe('breakdown:');
  expect(releasedMoldState).toBe('available:');
  passed('Production Manager releases paused equipment through the UI; the WO stays paused and its old capacity reservation is removed', {
    workOrderStatus: releasedRecovery.status,
    releasedAt: releasedRecovery.production_released_at,
    machine: releasedMachineState,
    mold: releasedMoldState,
    activeSchedule: null,
  });

  const maintenanceStart = await maintenanceStartViaUi(maintenance, corrective.id, 200);
  const correctiveAfterStart = dataOf(await at(maintenance, 'GET', `/maintenance/work-orders/${corrective.id}`));
  expect(correctiveAfterStart.status).toBe('in_progress');
  expect(correctiveAfterStart.started_at).toBeTruthy();
  expect(psql(`SELECT m.status || ':' || COALESCE(w.wo_number, '') FROM machines m
    LEFT JOIN work_orders w ON w.id = m.current_work_order_id WHERE m.machine_code = '${fixture.machine_code}'`, fixture.database)).toBe('maintenance:');
  expect(Number(psql(`SELECT count(*) FROM machine_downtimes
    WHERE maintenance_order_id = (SELECT id FROM maintenance_work_orders WHERE mwo_number = '${corrective.mwo_number}')
      AND end_time IS NULL`, fixture.database))).toBe(1);
  passed('Maintenance Technician starts the linked breakdown work order after authorized Production release; machine enters maintenance', {
    breakdownMwo: corrective.mwo_number,
    responseStatus: maintenanceStart.response.status(),
    status: correctiveAfterStart.status,
    machine: 'maintenance:',
  });

  const maintenanceComplete = await maintenanceCompleteViaUi(
    maintenance, corrective.id, 'Machine checked; safe to return to production.',
  );
  expect(maintenanceComplete.status).toBe('completed');
  expect(maintenanceComplete.completed_at).toBeTruthy();
  expect(psql(`SELECT m.status || ':' || COALESCE(w.wo_number, '') FROM machines m
    LEFT JOIN work_orders w ON w.id = m.current_work_order_id WHERE m.machine_code = '${fixture.machine_code}'`, fixture.database)).toBe('idle:');
  expect(Number(psql(`SELECT count(*) FROM machine_downtimes
    WHERE maintenance_order_id = (SELECT id FROM maintenance_work_orders WHERE mwo_number = '${corrective.mwo_number}')
      AND end_time IS NOT NULL`, fixture.database))).toBe(1);
  expect(dataOf(await at(manager, 'GET', `/production/work-orders/${fixture.recovery_work_order_id}`)).status).toBe('paused');
  passed('Maintenance completion closes linked downtime and restores the machine to idle while Production remains paused', {
    breakdownMwo: corrective.mwo_number,
    status: maintenanceComplete.status,
    completedAt: maintenanceComplete.completed_at,
    machine: 'idle:',
  });

  const unscheduledMain = dataOf(await at(manager, 'GET', `/production/work-orders/${fixture.main_work_order_id}/crew`));
  expect(unscheduledMain.schedule).toBeNull();
  const reviewedMainAfterBreakdown = await reviewSlotViaUi(
    manager, fixture.main_work_order_id, fixture.main_work_order_number,
    { startOffsetMinutes: 180, endOffsetMinutes: 300 },
  );
  expect(Date.parse(reviewedMainAfterBreakdown.scheduled_start)).toBeGreaterThan(Date.now());
  const reviewedResumeSlot = await reviewSlotViaUi(
    manager, fixture.recovery_work_order_id, fixture.recovery_work_order_number,
  );
  const beforeQcResume = await resumeState(
    manager, warehouse, fixture.recovery_work_order_id, fixture.recovery_work_order_number, qc,
  );
  expect(beforeQcResume.workOrder.status).toBe('paused');
  expect(beforeQcResume.workOrder.productionReleasedAt).toBeTruthy();
  expect(beforeQcResume.inProcessInspections.length).toBeGreaterThan(0);
  expect(beforeQcResume.inProcessInspections.some((inspection) => !['passed', 'cancelled'].includes(inspection.status))).toBe(true);
  const blockedResumeApi = await request(manager, 'POST', `/production/work-orders/${fixture.recovery_work_order_id}/resume`, {
    expected_crew_id: beforeQcResume.currentCrewId,
    presence_confirmed_employee_ids: [],
  });
  expect(blockedResumeApi.status, JSON.stringify(blockedResumeApi.body)).toBe(422);
  expect(blockedResumeApi.body.message).toMatch(/waiting for in-process QC/i);
  expect(await resumeState(
    manager, warehouse, fixture.recovery_work_order_id, fixture.recovery_work_order_number, qc,
  )).toEqual(beforeQcResume);
  const blockedResumeUi = await resumeViaUi(manager, fixture.recovery_work_order_id, 422);
  expect(blockedResumeUi.message).toMatch(/waiting for in-process QC/i);
  expect(await resumeState(
    manager, warehouse, fixture.recovery_work_order_id, fixture.recovery_work_order_number, qc,
  )).toEqual(beforeQcResume);
  passed('Paused production cannot resume before in-process Quality approval; API and UI reject without side effects', {
    apiStatus: blockedResumeApi.status,
    uiStatus: 422,
    message: blockedResumeApi.body.message,
    unchanged: beforeQcResume,
  });

  const recoveredInProcessQc = await measureReviewAll(
    qc, qcChecker, manager, managerId, fixture.recovery_work_order_id, 'in_process',
  );
  const readyToResume = await resumeState(
    manager, warehouse, fixture.recovery_work_order_id, fixture.recovery_work_order_number, qc,
  );
  expect(readyToResume.workOrder.status).toBe('paused');
  expect(readyToResume.workOrder.productionReleasedAt).toBe(beforeQcResume.workOrder.productionReleasedAt);
  expect(readyToResume.inProcessInspections.length).toBe(beforeQcResume.inProcessInspections.length);
  expect(readyToResume.inProcessInspections.filter((inspection) => inspection.status !== 'cancelled')
    .every((inspection) => inspection.status === 'passed')).toBe(true);
  const readyWorkOrder = dataOf(await at(manager, 'GET', `/production/work-orders/${fixture.recovery_work_order_id}`));
  expect(readyWorkOrder.process_quality_blocker).toBeNull();
  expect(readyToResume.machine).toBe('idle:');
  expect(readyToResume.mold).toBe('available:');
  expect(readyToResume.schedule).toMatchObject({ id: reviewedResumeSlot.id, status: 'confirmed' });
  expect(readyToResume.openDowntime).toBe(0);
  passed('Quality Inspector records the in-process result through the UI; paused WO stays released and its blocker clears', {
    inspections: recoveredInProcessQc,
    workOrderStatus: readyToResume.workOrder.status,
    processQualityBlocker: readyWorkOrder.process_quality_blocker,
    machine: readyToResume.machine,
    mold: readyToResume.mold,
    reviewedSchedule: readyToResume.schedule,
  });

  const resumedRecovery = await resumeViaUi(manager, fixture.recovery_work_order_id);
  expect(resumedRecovery.status).toBe('in_progress');
  expect(resumedRecovery.production_released_at).toBeNull();
  expect(resumedRecovery.actual_start).toBeTruthy();
  const afterQcResume = await resumeState(
    manager, warehouse, fixture.recovery_work_order_id, fixture.recovery_work_order_number, qc,
  );
  expect(afterQcResume.workOrder.status).toBe('in_progress');
  expect(afterQcResume.workOrder.productionReleasedAt).toBeNull();
  expect(afterQcResume.workOrder.actualStart).toBeTruthy();
  expect(afterQcResume.schedule).toMatchObject({ id: reviewedResumeSlot.id, status: 'confirmed' });
  expect(afterQcResume.activeSchedules).toBe(1);
  expect(afterQcResume.openDowntime).toBe(0);
  expect(afterQcResume.machine).toBe(`running:${fixture.recovery_work_order_number}`);
  expect(afterQcResume.mold).toBe(`in_use:${fixture.recovery_work_order_number}`);
  expect(afterQcResume.inProgressChainSteps).toBeGreaterThan(readyToResume.inProgressChainSteps);
  expect(afterQcResume.inProgressAuditEvents).toBe(readyToResume.inProgressAuditEvents + 1);
  passed('Maintenance Technician completes the breakdown MWO, then Production resumes only after final in-process Quality result', {
    breakdownMwo: corrective.mwo_number,
    start: maintenanceStart.response.status(),
    complete: maintenanceComplete.status,
    qualityInspections: recoveredInProcessQc.length,
    resume: 200,
  });
  await maintenance.context().close();

  // A good output is per-positive-output FG receipt; the operation ledger must reconcile.
  const recoveryOutput = await outputViaUi(outputRecorder, manager, warehouse, fixture.recovery_work_order_id, 2, 0, fixture.defect_type_id, 'recovery', { target: 2, produced: 2, good: 2, reject: 0 }, 2);
  expect(recoveryOutput.production_receipt_handoff.status).toBe('generated');
  await runOperationViaUi(
    manager, qc, qcChecker, managerId,
    fixture.recovery_work_order_id, fixture.recovery_work_order_number, 2, 2, 0,
  );
  await manager.goto(`/production/work-orders/${fixture.recovery_work_order_id}`);
  await manager.getByRole('button', { name: 'Complete', exact: true }).click();
  let completeDialog = manager.getByRole('dialog');
  let completePromise = manager.waitForResponse((response) =>
    response.url().endsWith(`/api/v1/production/work-orders/${fixture.recovery_work_order_id}/complete`));
  await completeDialog.getByRole('button', { name: 'Complete', exact: true }).click();
  expect((await completePromise).status()).toBe(200);
  await measureReviewAll(qc, qcChecker, manager, managerId, fixture.recovery_work_order_id, 'in_process');
  await assertCloseBlockedBeforeOutgoingQc(manager, fixture.recovery_work_order_id, fixture.recovery_work_order_number);
  const recoveryOutgoing = await measureReviewAll(qc, qcChecker, manager, managerId, fixture.recovery_work_order_id, 'outgoing');
  await assertAutoClosedAfterQuality(manager, fixture.recovery_work_order_id, fixture.recovery_work_order_number, fixture.qc_checker_email);
  passed('Recovery WO completed with routed output, in-process QC, output-bound outgoing review and audited automatic close', {
    good: recoveryOutput.good_count,
    fgReceipt: recoveryOutput.production_receipt_handoff.status,
    outgoingInspections: recoveryOutgoing.length,
  });

  // Issue the already-reserved Main target, then prove consumed material cannot be reversed after output.
  const mainIssue = await createIssueInUi(warehouse, fixture.main_work_order_id, 5, fixture.material_lot_number, fixture.main_work_order_number, 'reservation');
  await reviewSlotViaUi(manager, fixture.main_work_order_id, fixture.main_work_order_number);
  await startViaUi(manager, fixture.main_work_order_id, 200);
  const startedMain = dataOf(await at(manager, 'GET', `/production/work-orders/${fixture.main_work_order_id}`));
  expect(Number(startedMain.quantity_target)).toBe(5);
  expect(Number(startedMain.materials[0].actual_quantity_issued)).toBe(5);
  expect(Number(startedMain.material_cost_summary.actual_cost)).toBe(20);
  expect(startedMain.material_lot_references.some((row) => row.material_lot_number === fixture.material_lot_number)).toBe(true);
  expect(startedMain.process_quality_blocker).toMatch(/waiting for in-process QC .* approved final result/i);
  const mainRawBeforeQc = await stockAt(warehouse, fixture.raw_item_id, fixture.raw_location_id);
  const mainFgBeforeQc = await stockAt(warehouse, fixture.finished_item_id, fixture.fg_location_id);
  const outputsBeforeMainQc = dataOf(await at(manager, 'GET', `/production/work-orders/${fixture.main_work_order_id}/outputs`));
  expect(outputsBeforeMainQc).toHaveLength(0);
  await manager.goto(`/production/work-orders/${fixture.main_work_order_id}`);
  await expect(manager.getByText(startedMain.process_quality_blocker, { exact: true })).toBeVisible();
  await expect(manager.getByRole('button', { name: 'Record output', exact: true })).toHaveCount(0);
  await outputRecorder.goto(`/self-service/work-orders/${fixture.main_work_order_id}`);
  await expect(outputRecorder.getByRole('alert').filter({ hasText: startedMain.process_quality_blocker })).toBeVisible();
  await expect(outputRecorder.getByRole('button', { name: 'Record output', exact: true })).toHaveCount(0);
  await expect(outputRecorder.getByText('View only. Only the assigned output recorder can record quantities.', { exact: true })).toHaveCount(0);
  await screenshot(outputRecorder, 'main-output-blocked-pending-in-process-qc.png');
  const mainCrew = dataOf(await at(manager, 'GET', `/production/work-orders/${fixture.main_work_order_id}/crew`));
  const blockedOutput = await request(
    outputRecorder,
    'POST',
    `/production/my-work-orders/${fixture.main_work_order_id}/outputs`,
    {
      crew_id: mainCrew.current.id,
      good_count: 1,
      reject_count: 0,
      shift: fixture.shift_name,
      defects: [],
    },
    { 'X-Idempotency-Key': `audit-${RUN}-pre-qc-output` },
  );
  expect(blockedOutput.status, JSON.stringify(blockedOutput.body)).toBe(422);
  expect(blockedOutput.body.message).toMatch(/waiting for in-process QC .* approved final result/i);
  const mainAfterBlockedOutput = dataOf(await at(manager, 'GET', `/production/work-orders/${fixture.main_work_order_id}`));
  expect(mainAfterBlockedOutput.status).toBe(startedMain.status);
  expect(mainAfterBlockedOutput.quantity_produced).toBe(startedMain.quantity_produced);
  expect(mainAfterBlockedOutput.quantity_good).toBe(startedMain.quantity_good);
  expect(mainAfterBlockedOutput.quantity_rejected).toBe(startedMain.quantity_rejected);
  expect(dataOf(await at(manager, 'GET', `/production/work-orders/${fixture.main_work_order_id}/outputs`))).toHaveLength(0);
  const mainRawAfterBlockedOutput = await stockAt(warehouse, fixture.raw_item_id, fixture.raw_location_id);
  const mainFgAfterBlockedOutput = await stockAt(warehouse, fixture.finished_item_id, fixture.fg_location_id);
  expect(mainRawAfterBlockedOutput.quantity).toBe(mainRawBeforeQc.quantity);
  expect(mainRawAfterBlockedOutput.reserved_quantity).toBe(mainRawBeforeQc.reserved_quantity);
  expect(mainFgAfterBlockedOutput.quantity).toBe(mainFgBeforeQc.quantity);
  passed('Pending in-process QC blocks the Production UI and assigned-worker API output paths without changing counters, output rows or stock', {
    blocker: startedMain.process_quality_blocker,
    apiStatus: blockedOutput.status,
    apiMessage: blockedOutput.body.message,
    outputs: 0,
    rawStock: { before: mainRawBeforeQc.quantity, after: mainRawAfterBlockedOutput.quantity },
    finishedStock: { before: mainFgBeforeQc.quantity, after: mainFgAfterBlockedOutput.quantity },
  });

  const mainInProcessQc = await measureReviewAll(
    qc, qcChecker, manager, managerId, fixture.main_work_order_id, 'in_process',
  );
  const mainAfterQcRelease = dataOf(await at(manager, 'GET', `/production/work-orders/${fixture.main_work_order_id}`));
  expect(mainAfterQcRelease.status).toBe('in_progress');
  expect(mainAfterQcRelease.process_quality_blocker).toBeNull();
  expect(mainAfterQcRelease.actual_start).toBe(startedMain.actual_start);
  expect(dataOf(await at(manager, 'GET', `/production/work-orders/${fixture.main_work_order_id}/outputs`))).toHaveLength(0);
  await manager.reload();
  await expect(manager.getByRole('button', { name: 'Record output', exact: true })).toBeVisible();
  await outputRecorder.reload();
  await expect(outputRecorder.getByRole('alert')).toHaveCount(0);
  await expect(outputRecorder.getByLabel('Good count', { exact: true })).toBeEnabled();
  await expect(outputRecorder.getByRole('button', { name: 'Record output', exact: true })).toBeDisabled();
  passed('Quality Inspector records and approves main WO in-process QC in the Quality UI; only then Production output becomes available', {
    inspections: mainInProcessQc,
    status: mainAfterQcRelease.status,
    blocker: mainAfterQcRelease.process_quality_blocker,
    outputRowsBeforeProduction: 0,
  });

  const firstMainOutput = await outputViaUi(mobileOutputRecorder, mobileManager, warehouse, fixture.main_work_order_id, 3, 1, fixture.defect_type_id, 'partial', { target: 5, produced: 4, good: 3, reject: 1 }, 5);
  expect(firstMainOutput.production_receipt_handoff.status).toBe('generated');
  await outputRecorder.bringToFront();
  await outputRecorder.getByRole('button', { name: 'Refresh assignment', exact: true }).click();
  await expect(outputRecorder.getByText('2 pieces remaining · 3 good · 1 rejected', { exact: true })).toBeVisible();
  await expect(outputRecorder.getByText(firstMainOutput.batch_code, { exact: false })).toBeVisible();
  const mainFreshAfterFirst = dataOf(await at(mobileManager, 'GET', `/production/work-orders/${fixture.main_work_order_id}`));
  expect(mainFreshAfterFirst.quantity_target).toBe(5);
  expect(mainFreshAfterFirst.quantity_produced).toBe(4);
  expect(mainFreshAfterFirst.quantity_good).toBe(3);
  expect(mainFreshAfterFirst.quantity_rejected).toBe(1);
  await mobileManager.goto(`/production/work-orders/${fixture.main_work_order_id}`);
  await expect(mobileManager.locator('h1').getByText(fixture.main_work_order_number, { exact: true })).toBeVisible();
  await screenshot(mobileManager, 'main-partial-output-refreshed-detail.png');
  const stockBeforeForbiddenCancel = await stockAt(warehouse, fixture.raw_item_id, fixture.raw_location_id);
  const forbiddenCancel = await request(warehouse, 'DELETE', `/inventory/material-issues/${mainIssue.slip.id}`);
  expect(forbiddenCancel.status).toBe(422);
  expect(forbiddenCancel.body.message).toMatch(/after the work order has recorded output|may already have been consumed/i);
  const stockAfterForbiddenCancel = await stockAt(warehouse, fixture.raw_item_id, fixture.raw_location_id);
  expect(stockAfterForbiddenCancel.quantity).toBe(stockBeforeForbiddenCancel.quantity);
  expect(stockAfterForbiddenCancel.reserved_quantity).toBe(stockBeforeForbiddenCancel.reserved_quantity);
  passed('Partial output returns from UI with current counters while realtime business events are withheld; post-output Warehouse cancellation refuses and leaves stock unchanged', {
    target: mainFreshAfterFirst.quantity_target,
    produced: mainFreshAfterFirst.quantity_produced,
    good: mainFreshAfterFirst.quantity_good,
    rejected: mainFreshAfterFirst.quantity_rejected,
    cancellation: forbiddenCancel.status,
    stock: { before: stockBeforeForbiddenCancel.quantity, after: stockAfterForbiddenCancel.quantity },
  });
  await mobileManager.context().close();
  await mobileOutputRecorder.context().close();

  // The first output's reject consumed the sixth kilogram: gross production is
  // 4 so far against a 5 KG reserve, and the final 2 good units need the extra
  // kilogram issued before the coverage rule will accept them.
  const coverageCrew = dataOf(await at(manager, 'GET', `/production/work-orders/${fixture.main_work_order_id}/crew`));
  expect(coverageCrew.current?.id).toBe(mainCrew.current.id);
  const outputsBeforeCoverage = dataOf(await at(manager, 'GET', `/production/work-orders/${fixture.main_work_order_id}/outputs`));
  expect(outputsBeforeCoverage).toHaveLength(1);
  const stockBeforeCoverage = await stockAt(warehouse, fixture.raw_item_id, fixture.raw_location_id);
  const coverageBlocked = await request(manager, 'POST', `/production/work-orders/${fixture.main_work_order_id}/outputs`, {
    crew_id: coverageCrew.current.id,
    good_count: 2,
    reject_count: 0,
    shift: fixture.shift_name,
    defects: [],
    recording_reason: 'Production Manager checks material coverage before approving any on-behalf output.',
  });
  expect(coverageBlocked.status).toBe(422);
  expect(String(coverageBlocked.body.message)).toMatch(/coverage is short/i);
  const mainAfterCoverageBlock = dataOf(await at(manager, 'GET', `/production/work-orders/${fixture.main_work_order_id}`));
  expect(mainAfterCoverageBlock.status).toBe(mainFreshAfterFirst.status);
  expect(mainAfterCoverageBlock.quantity_produced).toBe(mainFreshAfterFirst.quantity_produced);
  expect(mainAfterCoverageBlock.quantity_good).toBe(mainFreshAfterFirst.quantity_good);
  expect(mainAfterCoverageBlock.quantity_rejected).toBe(mainFreshAfterFirst.quantity_rejected);
  expect(dataOf(await at(manager, 'GET', `/production/work-orders/${fixture.main_work_order_id}/outputs`)).map((item) => item.id))
    .toEqual(outputsBeforeCoverage.map((item) => item.id));
  const stockAfterCoverage = await stockAt(warehouse, fixture.raw_item_id, fixture.raw_location_id);
  expect(stockAfterCoverage.quantity).toBe(stockBeforeCoverage.quantity);
  expect(stockAfterCoverage.reserved_quantity).toBe(stockBeforeCoverage.reserved_quantity);
  const topUpIssue = await createIssueInUi(warehouse, fixture.main_work_order_id, 1, fixture.material_lot_number, fixture.main_work_order_number, 'general');
  const afterTopUp = await stockAt(warehouse, fixture.raw_item_id, fixture.raw_location_id);
  expect(Number(afterTopUp.quantity)).toBe(0);
  expect(Number(afterTopUp.reserved_quantity)).toBe(0);
  passed('Recording output past the issued material is refused with the exact shortage, and issuing the extra kilogram clears it', {
    blocked: coverageBlocked.status,
    message: coverageBlocked.body.message,
    topUpSlip: topUpIssue.slip.slip_number,
    stock: { quantity: afterTopUp.quantity, reserved: afterTopUp.reserved_quantity },
  });

  const secondMainOutput = await outputViaManagerUi(
    manager, warehouse, fixture.main_work_order_id, fixture.main_work_order_number, 2,
    'Assigned operator unavailable; Production Manager records shift-close count.',
    { target: 5, produced: 6, good: 5, reject: 1 }, 7,
  );
  expect(secondMainOutput.production_receipt_handoff.status).toBe('generated');
  await runOperationViaUi(
    manager, qc, qcChecker, managerId,
    fixture.main_work_order_id, fixture.main_work_order_number, 5, 5, 1,
  );
  await manager.goto(`/production/work-orders/${fixture.main_work_order_id}`);
  await manager.getByRole('button', { name: 'Complete', exact: true }).click();
  completeDialog = manager.getByRole('dialog');
  completePromise = manager.waitForResponse((response) =>
    response.url().endsWith(`/api/v1/production/work-orders/${fixture.main_work_order_id}/complete`));
  await completeDialog.getByRole('button', { name: 'Complete', exact: true }).click();
  expect((await completePromise).status()).toBe(200);
  await measureReviewAll(qc, qcChecker, manager, managerId, fixture.main_work_order_id, 'in_process');
  await assertCloseBlockedBeforeOutgoingQc(manager, fixture.main_work_order_id, fixture.main_work_order_number);
  const mainOutgoing = await measureReviewAll(qc, qcChecker, manager, managerId, fixture.main_work_order_id, 'outgoing', true);
  await assertAutoClosedAfterQuality(manager, fixture.main_work_order_id, fixture.main_work_order_number, fixture.qc_checker_email);

  const finalDetail = dataOf(await at(manager, 'GET', `/production/work-orders/${fixture.main_work_order_id}`));
  expect(finalDetail.status).toBe('closed');
  expect(finalDetail.quantity_target).toBe(5);
  expect(finalDetail.quantity_produced).toBe(6);
  expect(finalDetail.quantity_good).toBe(5);
  expect(finalDetail.quantity_rejected).toBe(1);
  expect(Number(finalDetail.materials[0].manual_quantity_issued)).toBe(6);
  expect(Number(finalDetail.material_cost_summary.actual_cost)).toBe(24);
  const finalOutputs = dataOf(await at(manager, 'GET', `/production/work-orders/${fixture.main_work_order_id}/outputs`));
  expect(finalOutputs).toHaveLength(2);
  expect(new Set(finalOutputs.map((item) => item.id))).toEqual(new Set([firstMainOutput.id, secondMainOutput.id]));
  const outputsById = new Map(finalOutputs.map((item) => [item.id, item]));
  expect([outputsById.get(firstMainOutput.id).good_count, outputsById.get(firstMainOutput.id).reject_count]).toEqual([3, 1]);
  expect([outputsById.get(secondMainOutput.id).good_count, outputsById.get(secondMainOutput.id).reject_count]).toEqual([2, 0]);
  expect(finalOutputs.every((item) => item.production_receipt_handoff.status === 'generated')).toBe(true);
  // Lineage is a per-output snapshot: the first output was recorded against the
  // reserved 5 KG, the final one after the extra kilogram was issued.
  const firstLineage = outputsById.get(firstMainOutput.id).material_lineage;
  const secondLineage = outputsById.get(secondMainOutput.id).material_lineage;
  expect([Number(firstLineage.materials[0].manual_quantity_issued), Number(firstLineage.materials[0].manual_actual_cost)]).toEqual([5, 20]);
  expect([Number(secondLineage.materials[0].manual_quantity_issued), Number(secondLineage.materials[0].manual_actual_cost)]).toEqual([6, 24]);
  for (const lineage of [firstLineage, secondLineage]) {
    expect(lineage.material_lot_references.some((row) => row.material_lot_number === fixture.material_lot_number)).toBe(true);
  }
  const finalStock = await stockAt(warehouse, fixture.raw_item_id, fixture.raw_location_id);
  expect(Number(finalStock.quantity)).toBe(0);
  expect(Number(finalStock.reserved_quantity)).toBe(0);
  expect(Number(finalStock.available)).toBe(0);
  const finalFg = await stockAt(warehouse, fixture.finished_item_id, fixture.fg_location_id);
  expect(Number(finalFg.quantity)).toBe(7);
  passed('Main work order completed, independently quality-reviewed and automatically closed; each positive output generated its own FG receipt', {
    status: finalDetail.status,
    target: finalDetail.quantity_target,
    produced: finalDetail.quantity_produced,
    good: finalDetail.quantity_good,
    rejected: finalDetail.quantity_rejected,
    material: finalDetail.materials[0],
    outputs: finalOutputs.map((item) => ({ good: item.good_count, reject: item.reject_count, receipt: item.production_receipt_handoff.status })),
    raw: { quantity: finalStock.quantity, reserved: finalStock.reserved_quantity, available: finalStock.available },
    finishedGoods: { quantity: finalFg.quantity, location: fixture.fg_location_id },
    outgoingInspections: mainOutgoing.length,
  });

  // The next module owns delivery creation; merely assert approved, output-bound FG eligibility.
  const releaseEligible = await at(manager, 'GET', `/quality/inspections?entity_type=work_order&entity_id=${encodeURIComponent(fixture.main_work_order_id)}&stage=outgoing&status=passed&per_page=100`);
  expect(dataOf(releaseEligible).length).toBeGreaterThan(0);
  report.handoffs.push({ from: 'Production / QC', to: 'Delivery', status: 'passed output-bound outgoing inspections are available as FG eligibility evidence; no Delivery record forced' });
  passed('Production hands approved output-bound inspections and posted FG to the next Delivery stage without forcing delivery', { passedOutgoingInspections: dataOf(releaseEligible).length, fg_quantity: finalFg.quantity });

  await screenshot(manager, 'closed-main-work-order.png');
  await screenshot(warehouse, 'warehouse-material-issue-detail.png');
  await outputRecorder.context().close();
  recordDiagnosticEvidence();
  assertCleanDiagnostics();
  if (jsErrors.length) throw new Error(`Browser page errors: ${jsErrors.join(' | ')}`);
  report.echo_websocket_sessions = echoWebsocketSessions;
  report.websocket_subscriptions_acknowledged = websocketSubscriptionsAcknowledged;
  report.websocket_pings_answered = websocketPingsAnswered;
  report.echo_websocket_urls_handled = echoWebsocketUrls;
  report.real_time_business_events_sent = 0;
  report.real_time_transport = 'inert Reverb protocol fixture; business events withheld';
  report.vite_hmr_websockets_observed = live.flatMap((entry) => entry.diagnostics.websocketConnections).filter((entry) => entry.isViteHmr).length;
  report.result = 'passed';
  report.finished_at = new Date().toISOString();
  report.javascript_errors = jsErrors;
  fs.writeFileSync(path.join(OUT, 'report.json'), JSON.stringify(report, null, 2));
  fs.writeFileSync(path.join(OUT, 'report.txt'), [
    `Production work-order browser acceptance ${RUN}`,
    `Database: ${fixture.database}`,
    `Checks: ${report.checks.length}`,
    `Cookie-authenticated roles: ${[...new Set(live.map((entry) => entry.email))].join(', ')}`,
    `Inert Reverb protocol test-double sessions: ${echoWebsocketSessions}; business events sent: 0`,
    `Artifacts: ${report.artifacts.length}`,
  ].join('\n') + '\n');
  console.log(`REPORT_DIR=${OUT}`);
  console.log(`REPORT_JSON=${path.join(OUT, 'report.json')}`);
  console.log(`CHECKS=${report.checks.length}`);
}

main().catch(async (error) => {
  report.result = 'failed';
  report.error = String(error?.stack ?? error);
  recordDiagnosticEvidence();
  report.echo_websocket_sessions = echoWebsocketSessions;
  report.websocket_subscriptions_acknowledged = websocketSubscriptionsAcknowledged;
  report.websocket_pings_answered = websocketPingsAnswered;
  report.echo_websocket_urls_handled = echoWebsocketUrls;
  report.real_time_business_events_sent = 0;
  report.real_time_transport = 'inert Reverb protocol fixture; business events withheld';
  report.vite_hmr_websockets_observed = live.flatMap((entry) => entry.diagnostics.websocketConnections).filter((entry) => entry.isViteHmr).length;
  report.javascript_errors = jsErrors;
  report.failures.push({ message: String(error?.message ?? error), stack: String(error?.stack ?? error) });
  await Promise.allSettled(live.map(async ({ page, email, diagnostics }, index) => {
    try {
      const name = email.replace(/[^a-z0-9]+/gi, '-').toLowerCase();
      const viewport = page.viewportSize();
      const context = `${index + 1}-${viewport?.width ?? 'unknown'}x${viewport?.height ?? 'unknown'}`;
      const file = path.join(OUT, `failure-${name}-${context}.png`);
      const pageState = await Promise.race([
        page.evaluate(() => {
          const root = document.querySelector('#root') ?? document.body;
          return {
            url: location.href,
            readyState: document.readyState,
            title: document.title,
            documentElementHtmlLength: document.documentElement?.outerHTML.length ?? 0,
            appRootHtmlLength: root?.outerHTML.length ?? 0,
            appRootText: root?.innerText?.slice(0, 3000) ?? '',
            scriptResources: performance.getEntriesByType('resource')
              .filter((entry) => entry.name.includes('.js'))
              .slice(-30)
              .map((entry) => ({ url: entry.name, duration: Math.round(entry.duration), transferSize: entry.transferSize })),
          };
        }),
        new Promise((resolve) => setTimeout(() => resolve({ evaluationTimedOut: true }), 2_000)),
      ]);
      await page.screenshot({ path: file, fullPage: true, timeout: 10_000 });
      report.artifacts.push(file);
      report.failures.push({ context, url: page.url(), screenshot: file, pageState, diagnostics });
    } catch (screenshotError) {
      report.failures.push({ screenshot_error: String(screenshotError), diagnostics });
    }
  }));
  fs.writeFileSync(path.join(OUT, 'report.json'), JSON.stringify(report, null, 2));
  console.error(error);
  console.error(`REPORT_DIR=${OUT}`);
  process.exitCode = 1;
}).finally(async () => {
  await Promise.allSettled(live.map(async (entry) => {
    await entry.context.close();
  }));
  if (sharedBrowser) await sharedBrowser.close();
  if (BOOTSTRAP) await tearDown(viteServer);
});
