import { firefox } from '../../spa/node_modules/playwright/index.mjs';
import fs from 'node:fs/promises';

const base = 'http://localhost';
const artifactDir = '/home/kwat0g/Desktop/kwatog/audit/live-2026-09-14/artifacts';
const password = 'password';
const outputId = 'GqkbAVwxd1';
const workOrderId = '0ldwDmpVa9';
const results = [];
const evidence = [];
let actor = 'signed-out';
let guard = 'none';
let requestNo = 0;

const browser = await firefox.launch({ headless: true });
const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
const page = await context.newPage();
const dataOf = (body) => body?.data ?? body;
const rowsOf = (body) => Array.isArray(dataOf(body)) ? dataOf(body) : (Array.isArray(dataOf(body)?.data) ? dataOf(body).data : []);
const short = (value) => JSON.stringify(value ?? '').slice(0, 2400);

function record(status, name, detail = '') {
  results.push({ status, actor, name, detail });
  console.log(`${status} ${actor} ${name}${detail ? ` :: ${detail}` : ''}`);
}

async function api(path, options = {}) {
  const result = await page.evaluate(async ({ path, options, requestId }) => {
    const redact = (value) => {
      if (Array.isArray(value)) return value.map(redact);
      if (!value || typeof value !== 'object') return value;
      return Object.fromEntries(Object.entries(value).map(([key, item]) => [key, /password|secret|token|cookie/i.test(key) ? '[REDACTED]' : redact(item)]));
    };
    const xsrf = document.cookie.split('; ').find((cookie) => cookie.startsWith('XSRF-TOKEN='))?.slice('XSRF-TOKEN='.length);
    const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-Request-ID': requestId, ...(xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } : {}), ...(options.body === undefined ? {} : { 'Content-Type': 'application/json' }) };
    const response = await fetch(path.startsWith('/api/') ? path : `/api/v1${path}`, { method: options.method ?? 'GET', credentials: 'include', headers, body: options.body === undefined ? undefined : JSON.stringify(options.body) });
    const text = await response.text();
    let body;
    try { body = JSON.parse(text); } catch { body = text; }
    return { status: response.status, body: redact(body), requestId: response.headers.get('x-request-id') ?? requestId };
  }, { path, options, requestId: `LIVE-B13-R-${++requestNo}` });
  evidence.push({ actor, method: options.method ?? 'GET', path, status: result.status, requestId: result.requestId, body: result.body });
  return result;
}

async function logout() {
  if (actor !== 'signed-out') await api('/auth/logout', { method: 'POST' }).catch(() => {});
  await context.clearCookies();
  actor = 'signed-out';
  guard = 'none';
}

async function login(alias, email) {
  await logout();
  await page.goto(`${base}/login`, { waitUntil: 'domcontentloaded', timeout: 30000 });
  await page.locator('input[type="email"], input[name="email"]').first().fill(email);
  await page.locator('input[type="password"]').first().fill(password);
  await page.getByRole('button', { name: /sign in|log in/i }).click();
  await page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 30000 }).catch(() => {});
  actor = alias;
  guard = 'internal';
  const user = await api('/auth/user');
  record(user.status === 200 ? 'PASS' : 'FAIL', `${alias} authenticated`, `HTTP ${user.status}; body=${short(user.body)}`);
}

try {
  await fs.mkdir(artifactDir, { recursive: true });
  await login('production', 'production@ogami.test');
  const before = await api(`/production/work-orders/${workOrderId}/outputs`);
  record(before.status === 200 ? 'PASS' : 'FAIL', 'L17 correction reads finished-goods output', `HTTP ${before.status}; body=${short(before.body)}`);
  const beforeRow = rowsOf(before.body).find((row) => row.id === outputId);
  const beforeHandoff = beforeRow?.production_receipt_handoff;
  const beforeMovements = rowsOf(before.body).map((row) => row.production_receipt_movement_id ?? row.receipt_movement_id).filter(Boolean);
  record(beforeHandoff?.status === 'manual_required' ? 'PASS' : 'FAIL', 'L17 correction sees manual-required receipt', `output=${outputId}; handoff=${short(beforeHandoff)}; movement_ids=${beforeMovements.join(',')}`);
  if (beforeHandoff?.status === 'manual_required') {
    const retry = await api(`/production/work-orders/${workOrderId}/outputs/${outputId}/retry-receipt`, { method: 'POST', body: {} });
    const expected = [200, 422].includes(retry.status);
    record(expected ? 'PASS' : 'FAIL', 'L17 narrow finished-goods receipt retry', `HTTP ${retry.status}; body=${short(retry.body)}`);
    const after = await api(`/production/work-orders/${workOrderId}/outputs`);
    const afterRows = rowsOf(after.body);
    const afterRow = afterRows.find((row) => row.id === outputId);
    const afterMovements = afterRows.map((row) => row.production_receipt_movement_id ?? row.receipt_movement_id).filter(Boolean);
    record(after.status === 200 ? 'PASS' : 'FAIL', 'L17 receipt retry stock reconciliation', `before_movements=${beforeMovements.join(',')}; after_movements=${afterMovements.join(',')}; after=${short(afterRow)}`);
    if (afterMovements.length > beforeMovements.length + 1) record('FAIL', 'L17 receipt retry duplicate stock guard', `before=${beforeMovements.length}; after=${afterMovements.length}`);
  }

  await login('warehouse', 'warehouse@ogami.test');
  const movements = await api('/inventory/stock-movements?per_page=200');
  record(movements.status === 200 ? 'PASS' : 'FAIL', 'L17 correction reads stock movements under Warehouse scope', `HTTP ${movements.status}; body=${short(movements.body)}`);
  const movementRows = rowsOf(movements.body);
  const explicitManual = movementRows.find((row) => String(row.gl_handoff_status ?? row.gl_status ?? row.journal_status ?? '') === 'manual_required');
  record(explicitManual ? 'OBSERVED' : 'BLOCKED', 'L17 correction stock GL manual target', explicitManual ? `movement=${explicitManual.id}; row=${short(explicitManual)}` : `No explicit manual_required stock GL handoff among ${movementRows.length} movements; Finance retry not sent.`);
  record('OBSERVED', 'L17 correction scope note', 'Finance has accounting.journal.post but not inventory.view; stock movement discovery was therefore performed as Warehouse and no unauthorized Finance list request is counted as product evidence.');
} catch (error) {
  record('FAIL', 'L17 recovery correction harness', error instanceof Error ? error.stack ?? error.message : String(error));
} finally {
  await logout().catch(() => {});
  await browser.close();
  await fs.writeFile(`${artifactDir}/l17-recovery-correction-results.json`, JSON.stringify({ generated_at: new Date().toISOString(), results, evidence }, null, 2));
}

console.log(`COUNTS ${JSON.stringify(results.reduce((out, row) => ({ ...out, [row.status]: (out[row.status] ?? 0) + 1 }), {}))}`);
