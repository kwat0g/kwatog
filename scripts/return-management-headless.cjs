// Real-API acceptance check through the SPA and API with one browser context per role.
//
//   node scripts/return-management-headless.cjs
//
// With RETURN_TEST_BOOTSTRAP=1 the runner is self-contained: it creates
// ogami_test_return_browser_<run>, migrates it, seeds the role fixture
// (api/tests/Browser/return_case_fixture.php), serves a temporary API and a
// temporary Vite proxy on it, then tears both down. The shared dev database is
// never touched. Without the flag it points at RETURN_TEST_URL and
// RETURN_TEST_FIXTURE, exactly as before.
const fs = require('node:fs');
const path = require('node:path');
const { randomUUID } = require('node:crypto');
const { execFileSync } = require('node:child_process');
const { pathToFileURL } = require('node:url');
const { createRequire } = require('node:module');

const ROOT = path.resolve(__dirname, '..');
const SPA = path.join(ROOT, 'spa');
// SPA-owned tooling (Tailwind, its Vite plugins, Playwright) resolves from
// spa/node_modules, so the runner works without NODE_PATH.
const spaRequire = createRequire(path.join(SPA, 'package.json'));
const { chromium, expect } = spaRequire('@playwright/test');

const BOOTSTRAP = process.env.RETURN_TEST_BOOTSTRAP === '1';
const RESUME = process.env.RETURN_TEST_RESUME === '1';
const RUN = (process.env.RETURN_RUN_ID || `RET${Date.now().toString(36)}`).toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 16);
const DB = `ogami_test_return_browser_${RUN.toLowerCase()}`;
const API_PORT = Number(process.env.RETURN_API_PORT || 8210);
const SPA_PORT = Number(process.env.RETURN_SPA_PORT || 5210);
const BASE = process.env.RETURN_TEST_URL || `http://127.0.0.1:${SPA_PORT}`;
const OUT = process.env.RETURN_TEST_OUTPUT || (BOOTSTRAP ? path.join('/tmp', `return-headless-${RUN}`) : '/tmp/ogami-return-headless');
let fixture;

/* ─── Self-contained environment (RETURN_TEST_BOOTSTRAP=1) ─────────── */

const FIXTURE_IN_CONTAINER = '/tmp/return-case-role-fixture.json';
const API_LOG_IN_CONTAINER = `/tmp/return-case-headless-${RUN}.api.log`;
const ENV = {
  DB_DATABASE: DB, CACHE_STORE: 'array', QUEUE_CONNECTION: 'sync', MAIL_MAILER: 'array',
  BROADCAST_CONNECTION: 'log', SESSION_DRIVER: 'database', SESSION_DOMAIN: new URL(BASE).hostname,
  SESSION_SECURE_COOKIE: 'false', SESSION_SAME_SITE: 'lax', APP_URL: BASE,
  COMPANY_VAT_STATUS: 'VAT Registered', TAX_PH_VAT_RATE: '0.12',
  SANCTUM_STATEFUL_DOMAINS: `127.0.0.1:${SPA_PORT},localhost:${SPA_PORT}`,
};
const compose = (args) => execFileSync('docker', ['compose', ...args], { cwd: ROOT, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'], maxBuffer: 64 * 1024 * 1024 });
const envFlags = () => Object.entries(ENV).flatMap(([k, v]) => ['-e', `${k}=${v}`]);
const artisan = (...args) => compose(['exec', '-T', ...envFlags(), 'api', 'php', 'artisan', ...args]);
const databaseUser = () => process.env.RETURN_TEST_DB_USERNAME || compose(['exec', '-T', 'db', 'printenv', 'POSTGRES_USER']).trim();
const psql = (query, db = 'postgres') => {
  return compose(['exec', '-T', 'db', 'psql', '-U', databaseUser(), '-d', db, '-At', '-c', query]).trim();
};
const apiIp = () => {
  const container = compose(['ps', '-q', 'api']).trim();
  return execFileSync('docker', ['inspect', '-f', '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}', container], { encoding: 'utf8' }).trim();
};

/** Free the port so a run killed before its teardown cannot poison this one. */
function stopApiServer() {
  for (const pattern of [`port=${API_PORT}`, `0.0.0.0:${API_PORT}`]) {
    try { compose(['exec', '-T', 'api', 'pkill', '-f', pattern]); } catch { /* not running */ }
  }
}

async function setUp() {
  fs.mkdirSync(OUT, { recursive: true });
  stopApiServer();
  if (RESUME) {
    if (!DB.startsWith('ogami_test_return_browser_')) throw new Error('Resume requires a dedicated return-browser test database.');
    if (psql(`SELECT 1 FROM pg_database WHERE datname='${DB}';`) !== '1') throw new Error(`Resume database ${DB} does not exist.`);
    fixture = JSON.parse(fs.readFileSync(path.join(OUT, 'fixture.json'), 'utf8'));
  } else {
    psql(`CREATE DATABASE ${DB} OWNER "${databaseUser().replace(/"/g, '""')}";`);
    artisan('migrate', '--force');
    compose(['exec', '-T', 'api', 'rm', '-f', FIXTURE_IN_CONTAINER]);
    artisan('tinker', '--execute', "require 'tests/Browser/return_case_fixture.php';");
    compose(['cp', `api:${FIXTURE_IN_CONTAINER}`, path.join(OUT, 'fixture.json')]);
    fixture = JSON.parse(fs.readFileSync(path.join(OUT, 'fixture.json'), 'utf8'));
  }
  compose(['exec', '-d', ...envFlags(), 'api', 'sh', '-c', 'log="$1"; shift; exec "$@" > "$log" 2>&1', 'sh', API_LOG_IN_CONTAINER, 'php', 'artisan', 'serve', '--no-reload', '--host=0.0.0.0', `--port=${API_PORT}`]);
  const target = `http://${apiIp()}:${API_PORT}`;
  let ready = false;
  for (let i = 0; i < 60; i++) {
    try {
      const csrf = await fetch(`${target}/sanctum/csrf-cookie`);
      const user = await fetch(`${target}/api/v1/auth/user`, { headers: { Accept: 'application/json' } });
      if (csrf.status < 500 && user.status < 500) { ready = true; break; }
    } catch { /* booting */ }
    await new Promise((resolve) => setTimeout(resolve, 1000));
  }
  if (!ready) throw new Error(`The API on :${API_PORT} never answered against ${DB}.`);
  const vite = await import(pathToFileURL(path.join(SPA, 'node_modules/vite/dist/node/index.js')).href);
  // Tailwind resolves its content globs from the working directory, so run the
  // SPA toolchain from the SPA root; every runner path is absolute.
  process.chdir(SPA);
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
  try {
    compose(['cp', `api:${API_LOG_IN_CONTAINER}`, path.join(OUT, 'server.log')]);
  } catch (error) {
    fs.writeFileSync(path.join(OUT, 'server-log-capture-error.txt'), error.message);
  }
  // The Vite server keeps the event loop alive: close it even when keeping the
  // database, or the runner never exits and the next run cannot bind the port.
  try { await server?.close(); } catch { /* already closed */ }
  // A failed run is the one worth inspecting: keep its database for a look.
  if (process.env.RETURN_TEST_KEEP === '1' || process.exitCode) {
    console.log(`Kept ${DB} and API :${API_PORT} for inspection.`);
    return;
  }
  stopApiServer();
  try { compose(['exec', '-T', 'api', 'rm', '-f', FIXTURE_IN_CONTAINER]); } catch { /* gone */ }
  try {
    psql(`SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname='${DB}' AND pid <> pg_backend_pid();`);
    psql(`DROP DATABASE IF EXISTS ${DB};`);
  } catch (e) { console.log(`Could not drop ${DB}: ${e.message}`); }
}
fs.mkdirSync(OUT, { recursive: true });
const stateFile = path.join(OUT, 'state.json');
const state = fs.existsSync(stateFile) ? JSON.parse(fs.readFileSync(stateFile, 'utf8')) : {};
const previousReport = (process.env.RETURN_TEST_PHASE === 'customer' || RESUME) && fs.existsSync(path.join(OUT, 'report.json')) ? JSON.parse(fs.readFileSync(path.join(OUT, 'report.json'), 'utf8')) : null;
const checks = previousReport?.checks ?? [];
const findings = state.findings || [];
state.findings = findings;
const pages = [];
const errors = [];
const httpErrors = [];
// Preserve browser diagnostics and status/path pairs for audit review.
const diagnostics = [];
const save = () => fs.writeFileSync(stateFile, JSON.stringify(state, null, 2));
const pass = (name) => { if (!checks.includes(name)) checks.push(name); console.log('PASS:', name); };
const internal = '/return-management/cases';
async function api(page, method, url, body) {
  const result = await page.evaluate(async ({ method, url, body }) => {
    const xsrf = document.cookie.split('; ').find((part) => part.startsWith('XSRF-TOKEN='))?.slice(11);
    const response = await fetch('/api/v1' + url, { method, credentials: 'include', headers: {
      Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest',
      ...(xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } : {}),
    }, ...(body ? { body: JSON.stringify(body) } : {}) });
    const data = await response.json();
    return { status: response.status, body: data };
  }, { method, url, body });
  return result;
}
async function ok(page, method, url, body) {
  const result = await api(page, method, url, body);
  if (result.status < 200 || result.status >= 300) throw new Error(`${method} ${url}: ${result.status} ${JSON.stringify(result.body).slice(0,1800)}`);
  return result.body.data ?? result.body;
}
async function login(browser, email, mobile = false) {
  const context = await browser.newContext({ baseURL: BASE, viewport: mobile ? { width: 390, height: 844 } : { width: 1440, height: 1000 }, reducedMotion: 'reduce' });
  const page = await context.newPage();
  page.setDefaultTimeout(20000);
  pages.push({ page, email });
  page.on('pageerror', (e) => errors.push(`${email}: ${e.message}`));
  page.on('console', (message) => {
    if (message.type() === 'error' && diagnostics.length < 200) diagnostics.push(`${email} console: ${message.text().slice(0, 300)}`);
  });
  page.on('requestfailed', (request) => {
    if (diagnostics.length < 200) diagnostics.push(`${email} requestfailed: ${request.method()} ${request.url().slice(0, 160)} (${request.failure()?.errorText ?? 'unknown'})`);
  });
  page.on('response', (response) => {
    if (response.status() >= 400 && httpErrors.length < 200) {
      httpErrors.push(`${email} HTTP ${response.status()}: ${response.request().method()} ${new URL(response.url()).pathname}`);
    }
    if (response.status() >= 500 && diagnostics.length < 200) diagnostics.push(`${email} http ${response.status()}: ${response.request().method()} ${response.url().slice(0, 160)}`);
  });
  await page.goto('/sign-in');
  await page.getByLabel('Email', { exact: true }).fill(email);
  await page.getByLabel('Password', { exact: true }).fill(process.env.RETURN_TEST_PASSWORD || 'password');
  const response = page.waitForResponse((r) => r.url().endsWith('/api/v1/auth/sign-in') && r.request().method() === 'POST');
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  const signedIn = await response;
  expect(signedIn.status(), `${email} login: ${await signedIn.text()}`).toBe(200);
  await expect(page).not.toHaveURL(/\/sign-in$/);
  pass(`Real cookie login: ${email}`);
  return page;
}
async function act(page, id, body) { return ok(page, 'POST', `${internal}/${id}/actions`, body); }
async function show(page, id) { return ok(page, 'GET', `${internal}/${id}`); }
async function casePage(page, id, realm = 'internal') {
  const url = realm === 'internal' ? `${internal}/${id}` : `/portal/${realm}/problems/${id}`;
  const heading = page.getByRole('heading', { name: 'Reported problem', exact: true });
  await page.goto(url, { waitUntil: 'domcontentloaded' });
  // The case detail is one route with several data panels; a cold API can take
  // longer than the default 5s assertion budget on a busy machine.
  try {
    await expect(heading).toBeVisible({ timeout: 20_000 });
  } catch {
    // Vite's dev-server module graph occasionally leaves a blank shell after a
    // long run. A user would reload; do the same once before failing.
    await page.reload({ waitUntil: 'domcontentloaded' });
    await expect(heading).toBeVisible({ timeout: 20_000 });
  }
}
async function submitIntake(page, kind, source, realm, expected, received, defective) {
  const url = realm === 'customer' ? '/portal/customer/problems' : internal;
  await page.goto(`${url}/new?source_kind=${kind}&source_id=${source}`);
  await expect(page.getByText('Affected goods', { exact: true })).toBeVisible();
  await page.getByRole('checkbox').first().check();
  if (expected !== null) await page.getByLabel('Expected for this shipment').fill(expected);
  for (const [label, value] of [['Actually received', received], ['Damaged or defective', defective]]) {
    const input = page.getByLabel(label, { exact: true });
    if (await input.isDisabled()) expect(Number(await input.inputValue())).toBe(Number(value));
    else await input.fill(value);
  }
  await page.getByLabel('Explain the problem').fill(realm === 'customer' ? 'Ten parts missing and two damaged when unpacked.' : (Number(defective) > 0 ? 'One kilogram of resin is contaminated.' : 'Ten kilograms missing from the supplier shipment.'));
  await page.getByLabel('Preferred outcome').selectOption(realm === 'customer' ? 'credit' : 'redelivery');
  if (realm === 'customer') await page.locator('input[type=file]').setInputFiles({ name: 'delivery-evidence.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF') });
  const response = page.waitForResponse((r) => r.request().method() === 'POST' && r.url().endsWith(realm === 'customer' ? '/api/v1/b2b/customer/problems' : '/api/v1/return-management/cases'));
  await page.getByRole('button', { name: 'Submit report', exact: true }).click();
  const submitted = await response;
  const body = await submitted.json();
  expect(submitted.status(), JSON.stringify(body)).toBe(201);
  await expect(page).toHaveURL(new RegExp(`${url}/${body.data.id}$`));
  return body.data.id;
}
async function agreeViaUi(page, id, resolution) {
  await casePage(page, id);
  await page.getByRole('button', { name: 'Agree action', exact: true }).click();
  await page.getByLabel('Agreed action and next step').fill(resolution === 'redelivery' ? 'Supplier will send the missing quantity in separate shipments.' : 'Verify the missing and damaged goods; Finance will review the credit.');
  await page.getByRole('combobox', { name: /^Resolution/ }).selectOption(resolution);
  const response = page.waitForResponse((r) => r.url().endsWith(`/cases/${id}/actions`) && r.request().method() === 'POST');
  await page.getByRole('button', { name: 'Record agreement' }).click();
  const saved = await response;
  expect(saved.status(), await saved.text()).toBe(200);
}
async function linkViaUi(page, id, grn) {
  await casePage(page, id);
  await page.getByRole('button', { name: 'Link completed work', exact: true }).first().click();
  await page.getByRole('checkbox', { name: grn.grn_number, exact: true }).check();
  const response = page.waitForResponse((r) => r.url().endsWith(`/cases/${id}/actions`) && r.request().method() === 'POST');
  await page.getByRole('button', { name: 'Save links', exact: true }).click();
  const saved = await response;
  expect(saved.status(), await saved.text()).toBe(200);
  return (await saved.json()).data;
}
async function rmaActionViaUi(page, id, action, confirm, path) {
  const endpoint = `/api/v1/return-management/return-requests/${id}${path}`;
  await page.goto(`/return-management/${id}`, { waitUntil: 'domcontentloaded' });
  await page.getByRole('button', { name: action, exact: true }).click();
  const dialog = page.getByRole('dialog');
  const responsePromise = page.waitForResponse((response) => response.request().method() === 'POST' && response.url().endsWith(endpoint));
  await dialog.getByRole('button', { name: confirm, exact: true }).click();
  const response = await responsePromise;
  const body = await response.json();
  expect(response.status(), JSON.stringify(body)).toBe(200);
  return body.data;
}
async function selectDispositions(dialog, lineCount, disposition) {
  await expect(dialog).toBeVisible();
  const fields = dialog.getByLabel('Disposition');
  expect(await fields.count()).toBe(lineCount);
  for (let i = 0; i < lineCount; i++) {
    const field = fields.nth(i);
    await expect.poll(() => field.locator('option').count(), { timeout: 20000 }).toBeGreaterThan(1);
    await field.selectOption(disposition);
    await expect(field).toHaveValue(disposition);
  }
}
async function createCustomerReturnViaUi(page) {
  await page.goto('/return-management/new', { waitUntil: 'domcontentloaded' });
  await expect(page.getByRole('heading', { name: 'New Return Request', exact: true })).toBeVisible();
  await page.getByLabel(/^Type\s*\*?$/).selectOption('customer_return');
  await page.getByLabel(/^Customer\s*\*?$/).selectOption(fixture.customer);
  await page.getByRole('button', { name: 'Add Item', exact: true }).click();
  const source = page.getByLabel('Source line', { exact: true });
  await expect.poll(() => source.locator('option').count(), { timeout: 20000 }).toBeGreaterThan(1);
  await source.selectOption(`delivery_item:${fixture.return_delivery_line}`);
  await expect(page.getByText('FG-BROWSER-RETURN — Browser test part', { exact: true })).toBeVisible();
  await page.getByLabel('Qty', { exact: true }).fill('2');
  const reason = page.getByLabel(/^Reason Code\s*\*?$/);
  const reasonValue = await reason.locator('option').evaluateAll((options) => options.find((option) => option.value)?.value);
  expect(reasonValue).toBeTruthy();
  await reason.selectOption(reasonValue);
  await page.getByLabel('Description', { exact: true }).fill('Returned for Quality inspection recovery test.');

  const responsePromise = page.waitForResponse((response) =>
    response.request().method() === 'POST' && response.url().endsWith('/api/v1/return-management/return-requests'));
  await page.getByRole('button', { name: 'Create Return Request', exact: true }).click();
  const response = await responsePromise;
  const body = await response.json();
  expect(response.status(), JSON.stringify(body)).toBe(201);
  expect(body.data).toMatchObject({ status: 'draft', type: 'customer_return' });
  expect(body.data.items[0].item_id).toBe(fixture.return_item);
  expect(body.data.items[0].source_delivery_item_id).toBe(fixture.return_delivery_line);
  pass('Customer Service creates a source-linked customer RMA in the real UI');
  return body.data.id;
}
async function approveReturn(browser, manager, rmaId, checker) {
  let rma = await ok(manager, 'GET', `/return-management/return-requests/${rmaId}`);
  if (rma.status === 'draft') {
    const submitted = await rmaActionViaUi(manager, rmaId, 'Submit for Approval', 'Submit', '/submit');
    expect(submitted.status).toBe('pending_approval');
    rma = await ok(manager, 'GET', `/return-management/return-requests/${rmaId}`);
  }
  if (rma.status === 'pending_approval' && rma.pending_approval_step === 1) {
    expect(rma).toMatchObject({ status: 'pending_approval', pending_approval_step: 1, disposition_status: null });
    expect(rma.approval_records.map((record) => [record.step_order, record.action, record.role_slug]))
      .toEqual([[1, 'pending', 'department_head'], [2, 'pending', 'production_manager']]);
    expect(rma.receipts).toHaveLength(0);
    expect(rma.inspections).toHaveLength(0);
    for (const actor of [manager, checker]) {
      await actor.goto(`/return-management/${rmaId}`, { waitUntil: 'domcontentloaded' });
      await expect(actor.locator('#main-content').getByText(rma.rma_number, { exact: true })).toBeVisible();
      await expect(actor.getByRole('button', { name: 'Approve', exact: true })).toHaveCount(0);
    }
    const wrongActor = await api(manager, 'POST', `/return-management/return-requests/${rmaId}/approve`);
    expect(wrongActor.status).toBe(403);
    expect(wrongActor.body.message).toMatch(/permission|approv/i);
    const afterWrongActor = await ok(manager, 'GET', `/return-management/return-requests/${rmaId}`);
    expect(afterWrongActor).toMatchObject({ status: 'pending_approval', pending_approval_step: 1 });
    expect(afterWrongActor.approval_records.map((record) => record.action)).toEqual(['pending', 'pending']);
    expect(afterWrongActor.receipts).toHaveLength(0);
    expect(afterWrongActor.inspections).toHaveLength(0);
    const outOfOrder = await api(checker, 'POST', `/return-management/return-requests/${rmaId}/approve`);
    expect(outOfOrder.status).toBe(403);
    expect(outOfOrder.body.message).toMatch(/role|step|approv/i);
    const afterOutOfOrder = await ok(manager, 'GET', `/return-management/return-requests/${rmaId}`);
    expect(afterOutOfOrder).toMatchObject({ status: 'pending_approval', pending_approval_step: 1 });
    expect(afterOutOfOrder.approval_records.map((record) => record.action)).toEqual(['pending', 'pending']);
    expect(afterOutOfOrder.receipts).toHaveLength(0);
    expect(afterOutOfOrder.inspections).toHaveLength(0);
    const head = await login(browser, 'depthead@ogami.test');
    const duplicateHead = await login(browser, fixture.concurrent_department_head);
    await head.goto(`/return-management/${rmaId}`, { waitUntil: 'domcontentloaded' });
    await head.getByRole('button', { name: 'Approve', exact: true }).click();
    const approvalDialog = head.getByRole('dialog');
    await expect(approvalDialog).toBeVisible();
    const uiApprovalResponsePromise = head.waitForResponse((response) => response.request().method() === 'POST' && response.url().endsWith(`/api/v1/return-management/return-requests/${rmaId}/approve`));
    const duplicateApprovalPromise = api(duplicateHead, 'POST', `/return-management/return-requests/${rmaId}/approve`);
    const uiApprovalClickPromise = approvalDialog.getByRole('button', { name: 'Approve', exact: true }).click();
    const [uiApprovalResponse, duplicateApproval] = await Promise.all([
      uiApprovalResponsePromise,
      duplicateApprovalPromise,
      uiApprovalClickPromise,
    ]).then(([response, duplicate]) => [response, duplicate]);
    const uiApprovalBody = await uiApprovalResponse.json();
    const approvalStatuses = [uiApprovalResponse.status(), duplicateApproval.status];
    expect(approvalStatuses.filter((status) => status === 200)).toHaveLength(1);
    const deniedStatus = approvalStatuses.find((status) => status !== 200);
    expect(deniedStatus).toBe(403);
    const deniedMessage = uiApprovalResponse.status() === 200 ? duplicateApproval.body.message : uiApprovalBody.message;
    expect(deniedMessage).toMatch(/different user|only users with role/i);
    const stepOne = await ok(head, 'GET', `/return-management/return-requests/${rmaId}`);
    expect(stepOne).toMatchObject({ status: 'pending_approval', pending_approval_step: 2 });
    expect(stepOne.approval_records.map((record) => [record.step_order, record.action]))
      .toEqual([[1, 'approved'], [2, 'pending']]);
    expect(stepOne.approval_records.filter((record) => record.action === 'approved')).toHaveLength(1);
    expect(stepOne.receipts).toHaveLength(0);
    expect(stepOne.inspections).toHaveLength(0);
    await Promise.all([head.context().close(), duplicateHead.context().close()]);
    pass('Step 2 cannot skip step 1; concurrent eligible approvers produce one approval effect');
    rma = stepOne;
  }
  if (rma.status === 'pending_approval' && rma.pending_approval_step === 2) {
    expect(rma.approval_records.map((record) => [record.step_order, record.action]))
      .toEqual([[1, 'approved'], [2, 'pending']]);
    expect(rma.receipts).toHaveLength(0);
    expect(rma.inspections).toHaveLength(0);
    const firstActor = rma.approval_records[0].approver.id;
    await rmaActionViaUi(checker, rmaId, 'Approve', 'Approve', '/approve');
    const approved = await ok(checker, 'GET', `/return-management/return-requests/${rmaId}`);
    expect(approved).toMatchObject({ status: 'approved', pending_approval_step: null, disposition_status: null });
    expect(approved.approval_records.map((record) => [record.step_order, record.action]))
      .toEqual([[1, 'approved'], [2, 'approved']]);
    expect(approved.approval_records[1].approver.id).not.toBe(firstActor);
    expect(approved.approval_records.map((record) => record.approver.id)).not.toContain(approved.creator.id);
    expect(approved.receipts).toHaveLength(0);
    expect(approved.inspections).toHaveLength(0);
    pass('RMA submission and both independent approvals run through role UI; state checked after each gate');
    return approved;
  }
  expect(rma.status).toBe('approved');
  expect(rma.approval_records.map((record) => [record.step_order, record.action]))
    .toEqual([[1, 'approved'], [2, 'approved']]);
  expect(rma.approval_records[0].approver.id).not.toBe(rma.approval_records[1].approver.id);
  return rma;
}
async function receiveSplit(warehouse, rmaId, first, second) {
  const url = `/return-management/return-requests/${rmaId}`;
  let rma = await ok(warehouse, 'GET', url);
  if (rma.status !== 'approved') return rma;
  const line = rma.items[0];
  if (Number(line.returned_quantity) === 0) {
    const shortFinal = await api(warehouse, 'POST', url + '/receive', { received_quantities: { [line.id]: first }, final_receipt: true, request_key: randomUUID(), quarantine_location_id: fixture.quarantine });
    expect(shortFinal.status, JSON.stringify(shortFinal.body)).toBe(422);
    expect(Number((await ok(warehouse, 'GET', url)).items[0].returned_quantity)).toBe(0);
    await warehouse.goto(`/return-management/${rmaId}`, { waitUntil: 'domcontentloaded' });
    await warehouse.getByRole('button', { name: 'Record Receipt', exact: true }).click();
    const partialDialog = warehouse.getByRole('dialog');
    await partialDialog.getByRole('spinbutton').first().fill(first);
    await partialDialog.getByRole('checkbox').uncheck();
    const partialResponsePromise = warehouse.waitForResponse((response) => response.request().method() === 'POST' && response.url().endsWith(`/api/v1/return-management/return-requests/${rmaId}/receive`));
    await partialDialog.getByRole('button', { name: 'Record Partial Receipt', exact: true }).click();
    const partialResponse = await partialResponsePromise;
    const partialBody = await partialResponse.json();
    expect(partialResponse.status(), JSON.stringify(partialBody)).toBe(200);
    const partialPayload = partialResponse.request().postDataJSON();
    expect(partialBody.data).toMatchObject({ status: 'approved', receipt_open: true, disposition_status: null });
    expect(partialBody.data.receipts).toHaveLength(1);
    expect(partialBody.data.receipts[0].final_receipt).toBe(false);
    expect(Number(partialBody.data.items[0].returned_quantity)).toBe(Number(first));
    expect(partialBody.data.inspections).toHaveLength(0);
    const retry = await api(warehouse, 'POST', url + '/receive', partialPayload);
    expect(retry.status, JSON.stringify(retry.body)).toBe(200);
    expect(retry.body.data.receipts).toHaveLength(1);
    expect(retry.body.data.receipts[0].id).toBe(partialBody.data.receipts[0].id);
    expect(Number(retry.body.data.items[0].returned_quantity)).toBe(Number(first));
    rma = await ok(warehouse, 'GET', url);
    expect(rma.status).toBe('approved');
    expect(rma.receipts).toHaveLength(1);
    pass('Warehouse records partial return in UI; same request retry adds no second receipt');
  }
  if (rma.status === 'approved') {
    await warehouse.goto(`/return-management/${rmaId}`, { waitUntil: 'domcontentloaded' });
    await warehouse.getByRole('button', { name: 'Record Receipt', exact: true }).click();
    const finalDialog = warehouse.getByRole('dialog');
    await finalDialog.getByRole('spinbutton').first().fill(second);
    await expect(finalDialog.getByRole('checkbox')).toBeChecked();
    const finalResponsePromise = warehouse.waitForResponse((response) => response.request().method() === 'POST' && response.url().endsWith(`/api/v1/return-management/return-requests/${rmaId}/receive`));
    await finalDialog.getByRole('button', { name: 'Record Final Receipt', exact: true }).click();
    const finalResponse = await finalResponsePromise;
    const finalBody = await finalResponse.json();
    expect(finalResponse.status(), JSON.stringify(finalBody)).toBe(200);
    rma = await ok(warehouse, 'GET', url);
    expect(rma.status).toBe('received');
    expect(rma.receipts).toHaveLength(2);
    expect(rma.receipts.map((receipt) => receipt.final_receipt)).toEqual([false, true]);
    expect(Number(rma.items[0].returned_quantity)).toBe(Number(first) + Number(second));
    expect(rma.inspections).toHaveLength(0);
    pass('Warehouse final receipt advances RMA only after full quantity; state checked before Quality');
  }
  return rma;
}
async function receiveFinalShortViaUi(warehouse, rmaId, quantity) {
  const url = `/return-management/return-requests/${rmaId}`;
  const before = await ok(warehouse, 'GET', url);
  expect(before.status).toBe('approved');
  await warehouse.goto(`/return-management/${rmaId}`, { waitUntil: 'domcontentloaded' });
  await warehouse.getByRole('button', { name: 'Record Receipt', exact: true }).click();
  const dialog = warehouse.getByRole('dialog');
  await dialog.getByRole('spinbutton').first().fill(quantity);
  await expect(dialog.getByRole('checkbox')).toBeChecked();
  const responsePromise = warehouse.waitForResponse((response) =>
    response.request().method() === 'POST' && response.url().endsWith(`/api/v1/return-management/return-requests/${rmaId}/receive`));
  await dialog.getByRole('button', { name: 'Record Final Receipt', exact: true }).click();
  const response = await responsePromise;
  const body = await response.json();
  expect(response.status(), JSON.stringify(body)).toBe(200);
  const received = await ok(warehouse, 'GET', url);
  expect(received).toMatchObject({ status: 'received', receipt_open: false, disposition_status: null });
  expect(received.receipts).toHaveLength(1);
  expect(received.receipts[0].final_receipt).toBe(true);
  expect(Number(received.items[0].returned_quantity)).toBe(Number(quantity));
  expect(Number(received.items[0].quantity)).toBeGreaterThan(Number(quantity));
  expect(received.inspections).toHaveLength(0);
  pass('Warehouse closes a shorter physical return at its actual quantity through the UI');
  return received;
}
async function cancelReturnViaUi(page, rmaId, expectedStatus) {
  const url = `/return-management/return-requests/${rmaId}`;
  const before = await ok(page, 'GET', url);
  expect(before.status).toBe(expectedStatus);
  expect(before.receipts).toHaveLength(0);
  await page.goto(`/return-management/${rmaId}`, { waitUntil: 'domcontentloaded' });
  await expect(page.locator('#main-content').getByText(before.rma_number, { exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'Cancel', exact: true }).click();
  const dialog = page.getByRole('dialog');
  await expect(dialog).toBeVisible();
  const responsePromise = page.waitForResponse((response) =>
    response.request().method() === 'POST' && response.url().endsWith(`/api/v1/return-management/return-requests/${rmaId}/cancel`));
  await dialog.getByRole('button', { name: 'Yes, cancel RMA', exact: true }).click();
  const response = await responsePromise;
  const body = await response.json();
  expect(response.status(), JSON.stringify(body)).toBe(200);
  const cancelled = await ok(page, 'GET', url);
  expect(cancelled).toMatchObject({
    status: 'cancelled',
    disposition_status: null,
    moved_quantity: null,
    stock_movement: null,
    credit_note: null,
  });
  expect(cancelled.receipts).toHaveLength(0);
  expect(cancelled.inspections).toHaveLength(0);
  expect(cancelled.items.every((line) => line.source_allocation === null && line.stock_movement_id === null)).toBe(true);
  const allocationState = psql(`select count(*)||'|'||count(*) filter (where a.released_at is not null and a.quantity=0)||'|'||count(*) filter (where a.released_at is null) from return_request_source_allocations a join return_request_items i on i.id=a.return_request_item_id join return_requests r on r.id=i.return_request_id where r.rma_number='${cancelled.rma_number}'`, DB);
  expect(allocationState).toBe('1|1|0');
  const approvalBlockers = psql(`select count(*) from approval_records a join return_requests r on r.id=a.approvable_id where a.approvable_type like '%ReturnRequest' and r.rma_number='${cancelled.rma_number}' and (a.action='pending' or a.is_current)`, DB);
  expect(approvalBlockers).toBe('0');
  const audit = () => psql(`select count(*) from audit_logs a join return_requests r on r.id=a.model_id where a.model_type like '%ReturnRequest' and r.rma_number='${cancelled.rma_number}' and a.action='updated' and a.user_id=(select id from users where email='customerservice@ogami.test') and a.old_values->>'status'='${expectedStatus}' and a.new_values->>'status'='cancelled'`, DB);
  expect(audit()).toBe('1');
  await page.goto(`/return-management/${rmaId}`, { waitUntil: 'domcontentloaded' });
  await expect(page.locator('#main-content').getByText(cancelled.rma_number, { exact: true })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Cancel', exact: true })).toHaveCount(0);
  const duplicate = await api(page, 'POST', `${url}/cancel`, { reason: 'Duplicate cancellation attempt.' });
  expect(duplicate.status).toBe(422);
  expect(duplicate.body.message).toMatch(/only draft, pending-approval, or approved returns can be cancelled/i);
  const afterDuplicate = await ok(page, 'GET', url);
  expect(afterDuplicate).toMatchObject({
    status: 'cancelled',
    cancelled_at: cancelled.cancelled_at,
    disposition_status: null,
    moved_quantity: null,
    stock_movement: null,
    credit_note: null,
  });
  expect(afterDuplicate.approval_records.map((record) => record.action)).not.toContain('pending');
  expect(afterDuplicate.receipts).toHaveLength(0);
  expect(afterDuplicate.inspections).toHaveLength(0);
  expect(afterDuplicate.items.every((line) => line.source_allocation === null && line.stock_movement_id === null)).toBe(true);
  expect(audit()).toBe('1');
  expect(psql(`select count(*) from return_request_source_allocations a join return_request_items i on i.id=a.return_request_item_id join return_requests r on r.id=i.return_request_id where r.rma_number='${cancelled.rma_number}' and a.released_at is not null and a.quantity=0`, DB)).toBe('1');
  pass(`Customer Service cancels an untouched ${expectedStatus} RMA through the UI and releases its source allocation`);
  return cancelled;
}
async function finishReturn(manager, qc, checker, rmaId, disposition) {
  const url = `/return-management/return-requests/${rmaId}`;
  let rma = await ok(manager, 'GET', url);
  if (rma.status === 'received') {
    const requiresProductInspection = rma.items.some((item) => Boolean(item.product_id));
    const inspected = await rmaActionViaUi(
      qc,
      rmaId,
      requiresProductInspection ? 'Stage Quality Handoff' : 'Continue to Disposition',
      requiresProductInspection ? 'Stage Handoff' : 'Continue',
      '/inspect',
    );
    expect(inspected.status).toBe('inspected');
    rma = await ok(manager, 'GET', url);
    expect(rma).toMatchObject({
      status: 'inspected',
      inspection_handoff: { status: requiresProductInspection ? 'generated' : 'not_required' },
      disposition_status: null,
      stock_movement: null,
    });
    if (requiresProductInspection) expect(rma.inspections.length).toBeGreaterThan(0);
    else expect(rma.inspections).toHaveLength(0);
    pass(`${requiresProductInspection ? 'QC stages product inspection' : 'Item-only return skips product inspection'} through role UI; RMA remains undisposed`);
  }
  if (rma.inspections?.some((inspection) => inspection.status === 'draft' || inspection.status === 'in_progress')) {
    const earlyDispositions = rma.items.map((item) => ({ item_id: item.id, disposition, notes: 'Attempt before return inspection is complete.' }));
    await manager.goto(`/return-management/${rmaId}`, { waitUntil: 'domcontentloaded' });
    await manager.getByRole('button', { name: 'Dispose Items', exact: true }).click();
    const earlyDialog = manager.getByRole('dialog');
    await selectDispositions(earlyDialog, rma.items.length, disposition);
    if (rma.type === 'supplier_return' && disposition === 'return_to_supplier') {
      const location = earlyDialog.getByLabel('Restock location', { exact: true });
      await expect.poll(() => location.locator('option').count(), { timeout: 20000 }).toBeGreaterThan(1);
      await location.selectOption(fixture.location);
    }
    const earlyResponsePromise = manager.waitForResponse((response) => response.request().method() === 'POST' && response.url().endsWith(`/api/v1/return-management/return-requests/${rmaId}/dispose`));
    await earlyDialog.getByRole('button', { name: 'Record Disposition', exact: true }).click();
    const earlyResponse = await earlyResponsePromise;
    const earlyBody = await earlyResponse.json();
    expect(earlyResponse.status(), JSON.stringify(earlyBody)).toBe(422);
    expect(earlyBody.message).toMatch(/inspection must be completed/i);
    const earlyUiState = await ok(manager, 'GET', url);
    expect(earlyUiState).toMatchObject({ status: 'inspected', disposition_status: null, stock_movement: null });
    const earlyApi = await api(manager, 'POST', url + '/dispose', {
      dispositions: earlyDispositions,
      ...(rma.type === 'supplier_return' && disposition === 'return_to_supplier' ? { location_id: fixture.location } : {}),
      create_replacement_po: false,
    });
    expect(earlyApi.status).toBe(422);
    const unchanged = await ok(manager, 'GET', url);
    expect(unchanged).toMatchObject({ status: 'inspected', disposition_status: null, stock_movement: null });
    pass('UI and direct API both block disposition until every return inspection completes');
  }
  for (const row of rma.inspections || []) {
    let inspection = await ok(qc, 'GET', `/quality/inspections/${row.id}`);
    if (['draft', 'in_progress'].includes(inspection.status)) {
      expect(inspection.inspection_mode).toBe('per_unit');
      await qc.goto(`/quality/inspections/${row.id}`, { waitUntil: 'domcontentloaded' });
      const manual = inspection.measurements.filter((measurement) => measurement.tolerance_min === null && measurement.tolerance_max === null);
      const numeric = inspection.measurements.filter((measurement) => measurement.tolerance_min !== null || measurement.tolerance_max !== null);
      const resultFields = qc.locator('select[aria-label$=" result"]');
      await expect.poll(() => resultFields.count(), { timeout: 20000 }).toBe(manual.length);
      for (let i = 0; i < manual.length; i++) await resultFields.nth(i).selectOption('pass');
      for (const measurement of numeric) {
        const label = `Measured value — ${measurement.parameter_name}, sample ${measurement.sample_index}${measurement.unit_of_measure ? ` (${measurement.unit_of_measure})` : ''}`;
        const value = measurement.nominal_value ?? measurement.target_value ?? measurement.tolerance_min ?? measurement.tolerance_max;
        expect(value, `${measurement.parameter_name} has a usable measurement`).not.toBeNull();
        await qc.getByLabel(label, { exact: true }).fill(String(value));
      }
      const save = qc.getByRole('button', { name: /^Save \(\d+\)$/ });
      await expect(save).toBeEnabled();
      const saveResponsePromise = qc.waitForResponse((response) => response.request().method() === 'PATCH' && response.url().endsWith(`/api/v1/quality/inspections/${row.id}/measurements`));
      await save.click();
      const saveResponse = await saveResponsePromise;
      expect(saveResponse.status(), await saveResponse.text()).toBe(200);
      inspection = await ok(qc, 'GET', `/quality/inspections/${row.id}`);
      expect(inspection.measurements.length).toBeGreaterThan(0);
      expect(inspection.measurements.every((measurement) => measurement.is_pass === true)).toBe(true);
      await qc.getByRole('button', { name: 'Complete', exact: true }).click();
      const completeDialog = qc.getByRole('dialog');
      const completeResponsePromise = qc.waitForResponse((response) => response.request().method() === 'POST' && response.url().endsWith(`/api/v1/quality/inspections/${row.id}/complete`));
      await completeDialog.getByRole('button', { name: 'Complete', exact: true }).click();
      const completeResponse = await completeResponsePromise;
      expect(completeResponse.status()).toBe(200);
      inspection = await ok(qc, 'GET', `/quality/inspections/${row.id}`);
    }
    if (inspection.status === 'awaiting_review') {
      expect((await api(qc, 'PATCH', `/quality/inspections/${row.id}/review`, { decision: 'passed' })).status).toBe(403);
      await checker.goto(`/quality/inspections/${row.id}`, { waitUntil: 'domcontentloaded' });
      await checker.getByRole('button', { name: 'Approve pass', exact: true }).click();
      const reviewDialog = checker.getByRole('dialog');
      const reviewResponsePromise = checker.waitForResponse((response) => response.request().method() === 'PATCH' && response.url().endsWith(`/api/v1/quality/inspections/${row.id}/review`));
      await reviewDialog.getByRole('button', { name: 'Approve pass', exact: true }).click();
      const reviewResponse = await reviewResponsePromise;
      expect(reviewResponse.status()).toBe(200);
      inspection = await ok(checker, 'GET', `/quality/inspections/${row.id}`);
      expect(inspection.status).toBe('passed');
      expect(inspection.reviewer.id).not.toBe(inspection.inspector.id);
      expect(inspection.result_authors.map((author) => author.id)).not.toContain(inspection.reviewer.id);
    }
    expect(['passed', 'failed']).toContain(inspection.status);
    rma = await ok(manager, 'GET', url);
    expect(rma).toMatchObject({ status: 'inspected', disposition_status: null, stock_movement: null });
  }
  if (rma.status !== 'completed') {
    if (rma.disposition_status !== 'disposed') {
      await manager.goto(`/return-management/${rmaId}`, { waitUntil: 'domcontentloaded' });
      await manager.getByRole('button', { name: 'Dispose Items', exact: true }).click();
      const disposeDialog = manager.getByRole('dialog');
      await selectDispositions(disposeDialog, rma.items.length, disposition);
      if ((rma.type === 'supplier_return' && disposition === 'return_to_supplier') || ['restock', 'rework'].includes(disposition)) {
        const location = disposeDialog.getByLabel('Restock location', { exact: true });
        await expect.poll(() => location.locator('option').count(), { timeout: 20000 }).toBeGreaterThan(1);
        await location.selectOption(fixture.location);
      }
      const disposeResponsePromise = manager.waitForResponse((response) => response.request().method() === 'POST' && response.url().endsWith(`/api/v1/return-management/return-requests/${rmaId}/dispose`));
      await disposeDialog.getByRole('button', { name: 'Record Disposition', exact: true }).click();
      const disposeResponse = await disposeResponsePromise;
      const disposeBody = await disposeResponse.json();
      expect(disposeResponse.status(), JSON.stringify(disposeBody)).toBe(200);
      rma = await ok(manager, 'GET', url);
      expect(rma).toMatchObject({ status: 'inspected', disposition_status: 'disposed' });
    }
    const moved = rma.items[0].moved_quantity;
    rma = await rmaActionViaUi(manager, rmaId, 'Complete RMA', 'Confirm Complete', '/complete');
    expect(rma.items[0].moved_quantity).toBe(moved);
    expect(rma.status).toBe('completed');
    const completed = await ok(manager, 'GET', url);
    expect(completed).toMatchObject({ status: 'completed', disposition_status: 'disposed' });
    expect(completed.items[0].moved_quantity).toBe(moved);
    expect(completed.completed_by).toBeTruthy();
  }
  pass(`${rma.type}: measurement, checker, disposition, and completion run through role UI; settled quantity preserved`);
  return ok(manager, 'GET', url);
}
async function finalizeCreditViaUi(finance, creditId) {
  let credit = await ok(finance, 'GET', `/accounting/credit-notes/${creditId}`);
  if (credit.status === 'draft') {
    await finance.goto(`/accounting/credit-notes/${creditId}`, { waitUntil: 'domcontentloaded' });
    const responsePromise = finance.waitForResponse((response) => response.request().method() === 'POST' && response.url().endsWith(`/api/v1/accounting/credit-notes/${creditId}/finalize`));
    await finance.getByRole('button', { name: 'Finalize + post', exact: true }).click();
    const response = await responsePromise;
    const body = await response.json();
    expect(response.status(), JSON.stringify(body)).toBe(200);
  }
  credit = await ok(finance, 'GET', `/accounting/credit-notes/${creditId}`);
  expect(credit.status).toBe('finalized');
  pass(`Finance posts credit note ${credit.credit_note_number} through Accounting UI`);
  return credit;
}
(async () => {
  let server;
  if (BOOTSTRAP) server = await setUp();
  else fixture = JSON.parse(fs.readFileSync(process.env.RETURN_TEST_FIXTURE || '/tmp/return-case-role-fixture.json', 'utf8'));
  const browser = await chromium.launch({ headless: true, args: ['--disable-dev-shm-usage', '--no-sandbox'] });
  try {
    if (process.env.RETURN_TEST_PHASE === 'links') {
      const service = await login(browser, 'customerservice@ogami.test');
      const record = await show(service, state.customerCase);
      const rma = await ok(service, 'GET', `/return-management/return-requests/${record.return_request.id}`);
      expect(rma.source_case.id).toBe(record.id);
      await service.goto(`/return-management/${rma.id}`);
      await service.getByRole('link', { name: `Problem: ${record.case_number}`, exact: true }).click();
      await expect(service).toHaveURL(new RegExp(`${internal}/${record.id}$`));
      pass('Internal RMA links back to the originating case');
      const customer = await login(browser, 'customer.return@ogami.test', true);
      await casePage(customer, record.id, 'customer');
      await customer.getByRole('link', { name: rma.rma_number, exact: true }).click();
      await expect(customer).toHaveURL(new RegExp(`/portal/customer/returns/${rma.id}$`));
      await customer.getByRole('link', { name: record.case_number, exact: true }).click();
      await expect(customer).toHaveURL(new RegExp(`/portal/customer/problems/${record.id}$`));
      await expect(customer.getByText('Report actions', { exact: true })).toHaveCount(0);
      pass('Customer follows case to physical return and back; closed case has no empty action panel');
      expect(errors).toEqual([]);
      fs.writeFileSync(path.join(OUT, 'links-report.json'), JSON.stringify({ headless: true, realApi: true, checks, errors }, null, 2));
      return;
    }
    if (process.env.RETURN_TEST_PHASE === 'quality-recovery') {
      const service = await login(browser, 'customerservice@ogami.test');
      const rmaId = await createCustomerReturnViaUi(service);
      const checker = await login(browser, 'production@ogami.test');
      await approveReturn(browser, service, rmaId, checker);
      const approvedForWarehouse = await ok(service, 'GET', `/return-management/return-requests/${rmaId}`);
      expect(approvedForWarehouse.status).toBe('approved');
      await service.goto(`/return-management/${rmaId}`, { waitUntil: 'domcontentloaded' });
      await expect(service.locator('#main-content').getByText(approvedForWarehouse.rma_number, { exact: true })).toBeVisible();
      await expect(service.getByRole('button', { name: 'Record Receipt', exact: true })).toHaveCount(0);
      const deniedReceipt = await api(service, 'POST', `/return-management/return-requests/${rmaId}/receive`, {
        received_quantities: { [approvedForWarehouse.items[0].id]: '1.000' },
        final_receipt: true,
        request_key: randomUUID(),
        quarantine_location_id: fixture.quarantine,
      });
      expect(deniedReceipt.status).toBe(403);
      const unchangedBeforeReceipt = await ok(service, 'GET', `/return-management/return-requests/${rmaId}`);
      expect(unchangedBeforeReceipt).toMatchObject({
        status: 'approved',
        receipt_open: false,
        disposition_status: null,
        moved_quantity: null,
        stock_movement: null,
        credit_note: null,
      });
      expect(unchangedBeforeReceipt.receipts).toHaveLength(0);
      expect(unchangedBeforeReceipt.inspections).toHaveLength(0);
      expect(unchangedBeforeReceipt.items.every((line) => line.moved_quantity === null && line.stock_movement_id === null)).toBe(true);
      pass('Customer Service cannot see or call Warehouse receipt actions');
      const warehouse = await login(browser, 'warehouse@ogami.test');
      await receiveFinalShortViaUi(warehouse, rmaId, '1.000');
      const qc = await login(browser, 'qc@ogami.test');
      const admin = await login(browser, 'admin@ogami.test');

      await admin.goto('/quality/inspection-specs', { waitUntil: 'domcontentloaded' });
      const specRow = admin.locator('tr').filter({ hasText: 'FG-BROWSER-RETURN' });
      await expect(specRow).toBeVisible();
      const archiveResponsePromise = admin.waitForResponse((response) =>
        response.request().method() === 'DELETE' && response.url().endsWith(`/api/v1/quality/inspection-specs/${fixture.return_spec}`));
      await specRow.getByRole('button', { name: 'Archive inspection spec', exact: true }).click();
      const archiveDialog = admin.getByRole('dialog');
      await expect(archiveDialog).toBeVisible();
      await archiveDialog.getByRole('button', { name: 'Archive', exact: true }).click();
      const archiveResponse = await archiveResponsePromise;
      const archiveBody = await archiveResponse.json();
      expect(archiveResponse.status(), JSON.stringify(archiveBody)).toBe(200);
      expect(archiveBody.data).toMatchObject({ is_active: false });
      const archivedSpec = await api(admin, 'GET', `/quality/products/${fixture.return_product}/inspection-spec`);
      expect(archivedSpec.status).toBe(200);
      expect(archivedSpec.body.data).toBeNull();
      pass('System Admin archives a Quality spec while the RMA is physically received');

      const handoff = await rmaActionViaUi(qc, rmaId, 'Stage Quality Handoff', 'Stage Handoff', '/inspect');
      expect(handoff).toMatchObject({
        status: 'received',
        inspection_handoff: { status: 'manual_required' },
        disposition_status: null,
        moved_quantity: null,
      });
      expect(handoff.inspections).toHaveLength(0);
      const atQualityGate = await ok(qc, 'GET', `/return-management/return-requests/${rmaId}`);
      expect(atQualityGate).toMatchObject({
        status: 'received',
        inspection_handoff: { status: 'manual_required' },
        disposition_status: null,
        moved_quantity: null,
        stock_movement: null,
        credit_note: null,
      });
      expect(atQualityGate.inspections).toHaveLength(0);
      expect(atQualityGate.items.every((line) => line.moved_quantity === null && line.stock_movement_id === null)).toBe(true);
      await service.goto(`/return-management/${rmaId}`, { waitUntil: 'domcontentloaded' });
      await expect(service.getByText('Quality inspection handoff needs attention', { exact: true })).toBeVisible();
      await expect(service.getByRole('button', { name: 'Stage Quality Handoff', exact: true })).toHaveCount(0);
      await expect(service.getByRole('button', { name: 'Retry Quality handoff', exact: true })).toHaveCount(0);
      await expect(service.getByRole('button', { name: 'Dispose Items', exact: true })).toHaveCount(0);
      const deniedStage = await api(service, 'POST', `/return-management/return-requests/${rmaId}/inspect`);
      expect(deniedStage.status).toBe(403);
      const afterDeniedStage = await ok(service, 'GET', `/return-management/return-requests/${rmaId}`);
      expect(afterDeniedStage).toMatchObject({ status: 'received', inspection_handoff: { status: 'manual_required' }, disposition_status: null, moved_quantity: null, stock_movement: null, credit_note: null });
      expect(afterDeniedStage.inspections).toHaveLength(0);
      const deniedRetry = await api(service, 'POST', `/return-management/return-requests/${rmaId}/retry-inspection`);
      expect(deniedRetry.status).toBe(403);
      const afterDeniedRetry = await ok(service, 'GET', `/return-management/return-requests/${rmaId}`);
      expect(afterDeniedRetry).toMatchObject({ status: 'received', inspection_handoff: { status: 'manual_required' }, disposition_status: null, moved_quantity: null, stock_movement: null, credit_note: null });
      expect(afterDeniedRetry.inspections).toHaveLength(0);
      const blockedDisposition = await api(service, 'POST', `/return-management/return-requests/${rmaId}/dispose`, {
        dispositions: [{ item_id: handoff.items[0].id, disposition: 'scrap' }],
      });
      expect(blockedDisposition.status).toBe(422);
      const unchanged = await ok(service, 'GET', `/return-management/return-requests/${rmaId}`);
      expect(unchanged).toMatchObject({ status: 'received', inspection_handoff: { status: 'manual_required' }, disposition_status: null, stock_movement: null });
      expect(unchanged.inspections).toHaveLength(0);
      expect(unchanged.credit_note).toBeNull();
      pass('Missing Quality spec leaves RMA blocked with a visible recovery action and no disposal side effects');

      await admin.goto(`/quality/inspection-specs/spec/${fixture.return_spec}`, { waitUntil: 'domcontentloaded' });
      const restoreResponsePromise = admin.waitForResponse((response) =>
        response.request().method() === 'PATCH' && response.url().endsWith(`/api/v1/quality/inspection-specs/${fixture.return_spec}/restore`));
      await admin.getByRole('button', { name: 'Restore spec', exact: true }).click();
      const restoreResponse = await restoreResponsePromise;
      const restoreBody = await restoreResponse.json();
      expect(restoreResponse.status(), JSON.stringify(restoreBody)).toBe(200);
      expect(restoreBody.data).toMatchObject({ is_active: true });
      pass('System Admin restores the source Quality spec through its UI');

      await qc.goto(`/return-management/${rmaId}`, { waitUntil: 'domcontentloaded' });
      const retryResponsePromise = qc.waitForResponse((response) =>
        response.request().method() === 'POST' && response.url().endsWith(`/api/v1/return-management/return-requests/${rmaId}/retry-inspection`));
      await qc.getByRole('button', { name: 'Retry Quality handoff', exact: true }).click();
      const retryResponse = await retryResponsePromise;
      const retryBody = await retryResponse.json();
      expect(retryResponse.status(), JSON.stringify(retryBody)).toBe(200);
      const staged = await ok(qc, 'GET', `/return-management/return-requests/${rmaId}`);
      expect(staged).toMatchObject({ status: 'inspected', inspection_handoff: { status: 'generated' } });
      expect(staged.inspections).toHaveLength(1);
      const stagedInspectionId = staged.inspections[0].id;
      const duplicateRetry = await api(qc, 'POST', `/return-management/return-requests/${rmaId}/retry-inspection`);
      expect(duplicateRetry.status).toBe(200);
      const afterDuplicateRetry = await ok(qc, 'GET', `/return-management/return-requests/${rmaId}`);
      expect(afterDuplicateRetry.inspections).toHaveLength(1);
      expect(afterDuplicateRetry.inspections[0].id).toBe(stagedInspectionId);
      expect(afterDuplicateRetry.inspection_id).toBe(staged.inspection_id);
      pass('QC retries after spec repair; repeated API retry reuses exactly one inspection');

      const completed = await finishReturn(service, qc, checker, rmaId, 'scrap');
      expect(completed.status).toBe('completed');
      expect(completed.inspections).toHaveLength(1);
      pass('Recovered RMA passes Quality review, disposition, and completion through role UIs');

      const pendingCancellationId = await createCustomerReturnViaUi(service);
      const submittedCancellation = await rmaActionViaUi(service, pendingCancellationId, 'Submit for Approval', 'Submit', '/submit');
      expect(submittedCancellation.status).toBe('pending_approval');
      const pendingCancellation = await ok(service, 'GET', `/return-management/return-requests/${pendingCancellationId}`);
      expect(pendingCancellation).toMatchObject({ status: 'pending_approval', pending_approval_step: 1, disposition_status: null });
      expect(pendingCancellation.approval_records.map((record) => record.action)).toEqual(['pending', 'pending']);
      expect(pendingCancellation.receipts).toHaveLength(0);
      expect(pendingCancellation.inspections).toHaveLength(0);
      await cancelReturnViaUi(service, pendingCancellationId, 'pending_approval');

      const approvedCancellationId = await createCustomerReturnViaUi(service);
      await approveReturn(browser, service, approvedCancellationId, checker);
      const approvedCancellation = await ok(service, 'GET', `/return-management/return-requests/${approvedCancellationId}`);
      expect(approvedCancellation).toMatchObject({ status: 'approved', pending_approval_step: null, disposition_status: null });
      expect(approvedCancellation.approval_records.map((record) => record.action)).toEqual(['approved', 'approved']);
      expect(approvedCancellation.receipts).toHaveLength(0);
      expect(approvedCancellation.inspections).toHaveLength(0);
      await cancelReturnViaUi(service, approvedCancellationId, 'approved');

      expect(httpErrors.filter((entry) => /GET \/api\/v1\/(vendors|inventory\/items)$/.test(entry))).toEqual([]);
      pass('Customer Service customer-return form makes no forbidden vendor or inventory-list requests');
      expect(errors).toEqual([]);
      fs.writeFileSync(path.join(OUT, 'report.json'), JSON.stringify({ headless: true, realApi: true, checks, findings, errors, diagnostics, http_errors: httpErrors, server_log: path.join(OUT, 'server.log') }, null, 2));
      console.log(`${checks.length} checks passed; ${findings.length} findings. Evidence: ${OUT}`);
      return;
    }
    let purchasing;
    let record;
    if (process.env.RETURN_TEST_PHASE !== 'customer') {
    purchasing = await login(browser, 'purchasing@ogami.test');
    if (!state.supplierCase) { state.supplierCase = await submitIntake(purchasing, 'purchase_order', fixture.po, 'internal', '10', '0', '0'); save(); }
    record = await show(purchasing, state.supplierCase);
    if (record.status === 'submitted') await agreeViaUi(purchasing, record.id, 'redelivery');
    pass('Purchasing submits and agrees a supplier shortage through the UI');
    const supplier = await login(browser, 'supplier.return@ogami.test');
    await casePage(supplier, state.supplierCase, 'supplier');
    await expect(supplier.getByRole('button', { name: 'Resolve case', exact: true })).toHaveCount(0);
    const denied = await api(supplier, 'POST', `/b2b/supplier/problems/${state.supplierCase}/actions`, { action: 'link_resolution', resolution_goods_receipt_note_ids: [] });
    expect(denied.status).toBe(403);
    if ((await show(purchasing, state.supplierCase)).status !== 'resolved') {
      await supplier.getByRole('button', { name: 'Acknowledge agreed action' }).click();
      await expect(supplier.getByText('Agreed action acknowledged.', { exact: true })).toBeVisible();
    }
    pass('Supplier can acknowledge but cannot settle its own case');
    const warehouse = await login(browser, 'warehouse@ogami.test');
    expect((await api(warehouse, 'POST', `${internal}/${state.supplierCase}/actions`, { action: 'resolve' })).status).toBe(403);
    pass('Warehouse cannot resolve a supplier case');
    const qc = await login(browser, 'qc@ogami.test');
    const checker = await login(browser, 'production@ogami.test');
    for (const quantity of ['4', '6']) {
      const key = `receipt${quantity}`;
      if (!state[key]) {
        const receipt = await ok(warehouse, 'POST', '/inventory/grn', { purchase_order_id: fixture.po, items: [{ purchase_order_item_id: fixture.po_line, item_id: fixture.item, location_id: fixture.location, quantity_received: quantity, received_uom_code: 'KG', unit_cost: '20.0000', material_lot_number: `HEADLESS-${quantity}` }] });
        state[key] = receipt.id; save();
      }
      let grn = await ok(warehouse, 'GET', `/inventory/grn/${state[key]}`);
      if (grn.status !== 'accepted') {
        await expect.poll(async () => { grn = await ok(warehouse, 'GET', `/inventory/grn/${state[key]}`); return grn.qc_inspection?.id; }, { timeout: 30000 }).toBeTruthy();
        let inspection = await ok(qc, 'GET', `/quality/inspections/${grn.qc_inspection.id}`);
        if (['draft', 'in_progress'].includes(inspection.status)) inspection = await ok(qc, 'POST', `/quality/inspections/${inspection.id}/lot-result`, { checklist: inspection.measurements.map((row) => ({ id: row.id, is_pass: true })), measurements: [], sample_defect_count: 0, complete: true });
        if (inspection.status === 'awaiting_review') {
          if (!state.qcSelfReviewChecked) {
            const review = await api(qc, 'PATCH', `/quality/inspections/${inspection.id}/review`, { decision: 'passed' });
            if (review.status === 200) {
              findings.push({ severity: 'high', area: 'Incoming QC', message: 'The QC user who recorded lot results could approve the same inspection (HTTP 200); inspector still referenced the warehouse creator.', inspection: inspection.id });
              inspection = review.body.data;
            } else expect(review.status).toBe(403);
            expect(review.status, 'The inspection result author must not review their own work').toBe(403);
            pass('Incoming QC result author cannot self-approve; independent checker can');
            state.qcSelfReviewChecked = true; save();
          }
          if (inspection.status === 'awaiting_review') await ok(checker, 'PATCH', `/quality/inspections/${inspection.id}/review`, { decision: 'passed', remarks: 'Accepted incoming replacement goods after independent review.' });
        }
        await expect.poll(async () => { grn = await ok(warehouse, 'GET', `/inventory/grn/${state[key]}`); return grn.status; }, { timeout: 30000 }).toBe('accepted');
      }
      pass(`Warehouse receives ${quantity} kg; completed QC makes it eligible for linking`);
      record = await show(purchasing, state.supplierCase);
      if (!record.resolution_receipts.some((receipt) => receipt.id === grn.id)) record = await linkViaUi(purchasing, state.supplierCase, grn);
      if (quantity === '4' && record.resolution_receipts.length === 1) {
        expect(record.lines[0].redelivered_quantity).toBe('4.000');
        expect(record.lines[0].remaining_redelivery_quantity).toBe('6.000');
        const response = purchasing.waitForResponse((r) => r.url().endsWith(`/cases/${state.supplierCase}/actions`) && r.request().method() === 'POST');
        await purchasing.getByRole('button', { name: 'Resolve case', exact: true }).click();
        expect((await response).status()).toBe(422);
        await expect(purchasing.getByRole('alert').first()).toBeVisible();
        await purchasing.screenshot({ path: path.join(OUT, 'purchasing-partial.png'), fullPage: true });
        state.partialVerified = true; save();
        pass('Partial receipt shows 4 received / 6 remaining and blocks early resolution');
      }
    }
    record = await show(purchasing, state.supplierCase);
    expect(state.partialVerified).toBe(true);
    expect(record.lines[0].redelivered_quantity).toBe('10.000');
    expect(record.lines[0].remaining_redelivery_quantity).toBe('0.000');
    expect(record.resolution_receipts).toHaveLength(2);
    if (record.status !== 'resolved') {
      const retry = await act(purchasing, record.id, { action: 'link_resolution', resolution_goods_receipt_note_ids: [state.receipt4, state.receipt6] });
      expect(retry.lines[0].redelivered_quantity).toBe('10.000');
      await casePage(purchasing, record.id);
      await purchasing.getByRole('button', { name: 'Resolve case', exact: true }).click();
      await expect.poll(async () => (await show(purchasing, record.id)).status).toBe('resolved');
    }
    pass('4 + 6 kg completes the shortage; retry cannot double-count accepted goods');
    await casePage(supplier, state.supplierCase, 'supplier');
    for (const receipt of record.resolution_receipts) await expect(supplier.getByText(receipt.grn_number, { exact: true })).toHaveCount(1);
    await supplier.screenshot({ path: path.join(OUT, 'supplier-resolved.png'), fullPage: true });
    pass('Supplier sees both receipts once and the completed case');
    if (!state.supplierDefectCase) { state.supplierDefectCase = await submitIntake(purchasing, 'grn', state.receipt4, 'internal', '4', '4', '1'); save(); }
    let defectCase = await show(purchasing, state.supplierDefectCase);
    if (defectCase.status === 'submitted') await agreeViaUi(purchasing, defectCase.id, 'return_goods');
    defectCase = await show(purchasing, defectCase.id);
    if (!defectCase.return_request) {
      await casePage(purchasing, defectCase.id);
      await purchasing.getByRole('button', { name: 'Create return request', exact: true }).first().click();
      const response = purchasing.waitForResponse((r) => r.url().endsWith(`/cases/${defectCase.id}/actions`) && r.request().method() === 'POST');
      await purchasing.getByRole('button', { name: 'Create return request', exact: true }).last().click();
      const created = await response;
      expect(created.status(), await created.text()).toBe(200);
      defectCase = await show(purchasing, defectCase.id);
    }
    const supplierRma = await ok(purchasing, 'GET', `/return-management/return-requests/${defectCase.return_request.id}`);
    expect(Number(supplierRma.items[0].quantity)).toBe(1);
    expect(supplierRma.type).toBe('supplier_return');
    await purchasing.screenshot({ path: path.join(OUT, 'supplier-defective-return.png'), fullPage: true });
    pass('Purchasing reports a raw-material defect against its GRN and prepares a 1 kg supplier return');
    await approveReturn(browser, purchasing, supplierRma.id, checker);
    await receiveSplit(warehouse, supplierRma.id, '0.400', '0.600');
    await finishReturn(purchasing, qc, checker, supplierRma.id, 'return_to_supplier');
    if ((await show(purchasing, defectCase.id)).status !== 'resolved') await act(purchasing, defectCase.id, { action: 'resolve' });
    pass('Supplier defective raw material is shipped back and its case closes');
    for (const page of [warehouse, qc, checker, supplier]) await page.context().close();
    }
    const customer = await login(browser, 'customer.return@ogami.test', true);
    if (!state.customerCase) { state.customerCase = await submitIntake(customer, 'delivery', fixture.delivery, 'customer', null, '90', '2'); save(); }
    await casePage(customer, state.customerCase, 'customer');
    const customerRecord = await ok(customer, 'GET', `/b2b/customer/problems/${state.customerCase}`);
    const submittedEvent = customerRecord.events.find((event) => event.action === 'submitted');
    if (submittedEvent && Math.abs(Date.parse(customerRecord.created_at) - Date.parse(submittedEvent.created_at)) > 60000 && !findings.some((finding) => finding.area === 'Case timeline')) {
      findings.push({ severity: 'medium', area: 'Case timeline', message: 'The submitted event appears eight hours before case creation; application and database-default timestamps use inconsistent time zones.', caseCreated: customerRecord.created_at, eventCreated: submittedEvent.created_at });
      save();
    }
    expect(Math.abs(Date.parse(customerRecord.created_at) - Date.parse(submittedEvent.created_at))).toBeLessThan(60000);
    pass('Submitted event and case creation agree on timezone');
    expect(customerRecord.lines[0].missing_quantity).toBe('10.000');
    expect(customerRecord.lines[0].defective_quantity).toBe('2.000');
    expect(customerRecord.attachments.length).toBeGreaterThan(0);
    expect(await customer.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    await customer.screenshot({ path: path.join(OUT, 'customer-mobile-report.png'), fullPage: true });
    expect((await api(customer, 'GET', `/b2b/customer/problems/${state.supplierCase}`)).status).toBe(403);
    pass('Customer mobile reports missing + damaged goods, uploads evidence, and cannot access supplier cases');
    const service = await login(browser, 'customerservice@ogami.test');
    record = await show(service, state.customerCase);
    if (['submitted', 'information_needed', 'under_review'].includes(record.status)) {
      if (record.status === 'submitted') await act(service, record.id, { action: 'request_info', message: 'Please confirm the carton count.' });
      await casePage(customer, record.id, 'customer');
      await customer.getByRole('textbox', { name: /^Message/ }).fill('Nine cartons arrived; two parts were cracked.');
      await customer.getByRole('button', { name: 'Send reply', exact: true }).click();
      await expect(customer.getByText('Nine cartons arrived; two parts were cracked.', { exact: true })).toBeVisible();
      await agreeViaUi(service, record.id, 'credit');
    }
    record = await show(service, state.customerCase);
    if (!record.return_request) {
      await casePage(service, record.id);
      await service.getByRole('button', { name: 'Create return request', exact: true }).first().click();
      const createResponsePromise = service.waitForResponse((response) => response.request().method() === 'POST' && response.url().endsWith(`/api/v1/return-management/cases/${record.id}/actions`));
      await service.getByRole('button', { name: 'Create return request', exact: true }).last().click();
      const createResponse = await createResponsePromise;
      const createBody = await createResponse.json();
      expect(createResponse.status(), JSON.stringify(createBody)).toBe(200);
      record = await show(service, state.customerCase);
      expect(record.return_request).toBeTruthy();
      pass('Customer Service creates physical return from the case UI');
    }
    record = await show(service, state.customerCase);
    const customerRma = await ok(service, 'GET', `/return-management/return-requests/${record.return_request.id}`);
    expect(Number(customerRma.items[0].quantity)).toBe(2);
    pass('Customer Service follows up, customer replies, and only the 2 damaged goods enter the physical return');
    if (purchasing) await purchasing.context().close();
    const finance = await login(browser, 'finance@ogami.test');
    await casePage(finance, state.customerCase);
    await expect(finance.getByRole('button', { name: 'Agree action', exact: true })).toHaveCount(0);
    record = await show(finance, state.customerCase);
    if (!record.credit_note) {
      await finance.getByRole('button', { name: 'Create credit note', exact: true }).click();
      const response = finance.waitForResponse((r) => r.url().endsWith(`/cases/${state.customerCase}/actions`) && r.request().method() === 'POST');
      await finance.getByRole('button', { name: 'Create credit note', exact: true }).last().click();
      const saved = await response;
      expect(saved.status(), await saved.text()).toBe(200);
    }
    record = await show(finance, state.customerCase);
    expect(record.credit_note.status).toBe('draft');
    const beforeResolveAttempt = await show(service, state.customerCase);
    await casePage(service, state.customerCase);
    const earlyResolvePromise = service.waitForResponse((response) => response.request().method() === 'POST' && response.url().endsWith(`/api/v1/return-management/cases/${state.customerCase}/actions`));
    await service.getByRole('button', { name: 'Resolve case', exact: true }).click();
    const earlyResolve = await earlyResolvePromise;
    const earlyResolveBody = await earlyResolve.json();
    expect(earlyResolve.status(), JSON.stringify(earlyResolveBody)).toBe(422);
    await expect(service.getByRole('alert').first()).toBeVisible();
    const directEarlyResolve = await api(service, 'POST', `${internal}/${state.customerCase}/actions`, { action: 'resolve' });
    expect(directEarlyResolve.status).toBe(422);
    const afterResolveAttempt = await show(service, state.customerCase);
    expect(afterResolveAttempt.status).toBe(beforeResolveAttempt.status);
    expect(afterResolveAttempt.credit_note.id).toBe(beforeResolveAttempt.credit_note.id);
    await finance.screenshot({ path: path.join(OUT, 'finance-credit-draft.png'), fullPage: true });
    pass('Finance prepares a credit draft; incomplete return/credit cannot be marked resolved');
    const returnWarehouse = await login(browser, 'warehouse@ogami.test');
    const returnQc = await login(browser, 'qc@ogami.test');
    const returnChecker = await login(browser, 'production@ogami.test');
    const returnManager = service;
    await approveReturn(browser, service, customerRma.id, returnChecker);
    await receiveSplit(returnWarehouse, customerRma.id, '1.000', '1.000');
    const completed = await finishReturn(returnManager, returnQc, returnChecker, customerRma.id, process.env.RETURN_TEST_DISPOSITION || 'scrap');
    expect(completed.credit_note).toBeTruthy();
    expect((await api(service, 'POST', `${internal}/${state.customerCase}/actions`, { action: 'resolve' })).status).toBe(422);
    let credited = 0;
    for (const creditId of new Set([record.credit_note.id, completed.credit_note.id])) {
      let credit = await ok(finance, 'GET', `/accounting/credit-notes/${creditId}`);
      credited += Number(credit.subtotal);
      credit = await finalizeCreditViaUi(finance, creditId);
    }
    expect(credited).toBe(300);
    await casePage(service, state.customerCase);
    const resolvePromise = service.waitForResponse((response) => response.request().method() === 'POST' && response.url().endsWith(`/api/v1/return-management/cases/${state.customerCase}/actions`));
    await service.getByRole('button', { name: 'Resolve case', exact: true }).click();
    const resolveResponse = await resolvePromise;
    const resolveBody = await resolveResponse.json();
    expect(resolveResponse.status(), JSON.stringify(resolveBody)).toBe(200);
    expect((await show(service, state.customerCase)).status).toBe('resolved');
    await casePage(customer, state.customerCase, 'customer');
    const settled = await ok(customer, 'GET', `/b2b/customer/problems/${state.customerCase}`);
    expect(settled.status).toBe('resolved');
    await customer.screenshot({ path: path.join(OUT, 'customer-completed-credit.png'), fullPage: true });
    pass('Finance issues 250 shortage + 50 defect credit; customer sees the resolved case');
    expect(errors).toEqual([]);
    pass('No uncaught browser errors');
    fs.writeFileSync(path.join(OUT, 'report.json'), JSON.stringify({ headless: true, realApi: true, checks, findings, state, errors, diagnostics, http_errors: httpErrors, server_log: path.join(OUT, 'server.log') }, null, 2));
    console.log(`${checks.length} checks passed; ${findings.length} findings. Evidence: ${OUT}`);
    if (findings.length) process.exitCode = 1;
  } catch (error) {
    for (const { page, email } of pages) {
      if (!page.isClosed()) {
        await page.screenshot({ path: path.join(OUT, `failure-${email.replace(/[^a-z]/gi, '-')}.png`), fullPage: true }).catch(() => {});
        fs.writeFileSync(path.join(OUT, `failure-${email}.txt`), await page.locator('body').innerText().catch(() => 'Unavailable'));
      }
    }
    fs.writeFileSync(path.join(OUT, process.env.RETURN_TEST_PHASE === 'links' ? 'links-report.json' : 'report.json'), JSON.stringify({ headless: true, realApi: true, checks, findings, state, errors, diagnostics, http_errors: httpErrors, server_log: path.join(OUT, 'server.log'), failure: String(error.stack) }, null, 2));
    console.error(error);
    process.exitCode = 1;
  } finally {
    await browser.close();
    if (BOOTSTRAP) await tearDown(server);
  }
})();
