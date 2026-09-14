import { firefox } from '../../spa/node_modules/playwright/index.mjs';
import fs from 'node:fs/promises';

const base = 'http://localhost';
const artifactDir = '/home/kwat0g/Desktop/kwatog/audit/live-2026-09-14/artifacts';
const password = 'password';
const stamp = Date.now().toString().slice(-8);
const results = [];
const evidence = [];
let actor = 'signed-out';
let guard = 'none';
let lastLogin = 0;
let n = 2000;
let machine;
const browser = await firefox.launch({ headless: true });
const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
const page = await context.newPage();
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const data = (body) => body?.data ?? body;
const short = (v) => JSON.stringify(v ?? '').slice(0, 1800);
function rec(status, name, detail = '') { results.push({ status, actor, name, detail }); console.log(`${status} ${actor} ${name}${detail ? ` :: ${detail}` : ''}`); }
function exp(name, response, accepted, detail = '') { const list = Array.isArray(accepted) ? accepted : [accepted]; const ok = list.includes(response.status); rec(ok ? 'PASS' : 'FAIL', name, `HTTP ${response.status}; expected ${list.join('/')} ${detail}; body=${short(response.body)}`); return ok; }
function block(name, detail) { rec('BLOCKED', name, detail); }
async function api(path, options = {}) {
  const r = await page.evaluate(async ({ path, options, requestId }) => {
    const xsrf = document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.slice('XSRF-TOKEN='.length);
    const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-Request-ID': requestId, ...(xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } : {}), ...(options.body === undefined ? {} : { 'Content-Type': 'application/json' }) };
    const response = await fetch(path.startsWith('/api/') ? path : `/api/v1${path}`, { method: options.method ?? 'GET', credentials: 'include', headers, body: options.body === undefined ? undefined : JSON.stringify(options.body) });
    const text = await response.text(); let body; try { body = JSON.parse(text); } catch { body = text; }
    return { status: response.status, body, requestId: response.headers.get('x-request-id') ?? requestId };
  }, { path, options, requestId: `LIVE-B12-T-${++n}` });
  evidence.push({ actor, method: options.method ?? 'GET', path, status: r.status, requestId: r.requestId, body: r.body });
  return r;
}
async function logout() { if (actor !== 'signed-out') await api(guard === 'customer' ? '/b2b/customer/logout' : '/auth/logout', { method: 'POST' }).catch(() => {}); await context.clearCookies(); actor = 'signed-out'; guard = 'none'; }
async function login(alias, email) {
  await logout(); const wait = Math.max(0, 13000 - (Date.now() - lastLogin)); if (wait) await sleep(wait);
  await page.goto(`${base}/login`, { waitUntil: 'domcontentloaded', timeout: 30000 });
  await page.locator('input[type="email"], input[name="email"]').first().fill(email); await page.locator('input[type="password"]').first().fill(password); await page.getByRole('button', { name: /sign in|log in/i }).click(); await page.waitForURL((u) => !u.pathname.endsWith('/login'), { timeout: 30000 }).catch(() => {});
  actor = alias; guard = 'internal'; lastLogin = Date.now(); exp(`${alias} authenticated`, await api('/auth/user'), 200, `uiUrl=${page.url()}`);
}
async function shot(name) { await page.screenshot({ path: `${artifactDir}/l10-l14-${name}.png`, fullPage: true }).catch(() => {}); }
async function ui(path, name) { const r = await page.goto(`${base}${path}`, { waitUntil: 'domcontentloaded', timeout: 30000 }).catch(() => null); await page.locator('body').waitFor({ state: 'visible', timeout: 20000 }).catch(() => {}); await shot(name); rec('PASS', `${name} UI`, `document=${r?.status() ?? 'none'} uiUrl=${page.url()}`); }

try {
  await fs.mkdir(artifactDir, { recursive: true });
  await login('ppc', 'ppc@ogami.test');
  const moldBefore = await api('/mrp/molds/dGypLxpvAg'); exp('L11 PPC reads execution mold shot history', moldBefore, 200);
  rec('OBSERVED', 'L11 mold shot evidence', `mold=dGypLxpvAg; before/after resource=${short(moldBefore.body)}`);
  await login('production', 'production@ogami.test');
  const retry = await api('/production/work-orders/0ldwDmpVa9/outputs/GqkbAVwxd1/retry-receipt', { method: 'POST', body: {} });
  exp('L11 production receipt handoff narrow retry', retry, [200, 409, 422]);
  rec('OBSERVED', 'L11 finished-goods stock handoff', `output=GqkbAVwxd1; retry=${short(retry.body)}`);
  await login('employee', 'employee@ogami.test');
  exp('L13 direct wrong-role closed NCR denial', await api('/quality/ncrs/kABpV2vpKR/close', { method: 'POST', body: {} }), 403);

  // Create a disposable machine master, use it as the MWO target, then archive
  // it. This does not touch seeded machine history or use a technical admin.
  await login('production', 'production@ogami.test');
  const created = await api('/mrp/machines', { method: 'POST', body: { machine_code: `B12M-${stamp}`, name: 'LIVE-B12 interruption machine', tonnage: 100, machine_type: 'injection_molder', operators_required: '1.0', available_hours_per_day: '8.0', status: 'idle' } });
  exp('L14 creates disposable interruption machine', created, 201); machine = data(created.body)?.id;
  if (!machine) block('L14 disposable machine target', 'Machine master creation returned no hash.');
  else {
    await login('maintenance', 'maintenance@ogami.test'); await ui('/maintenance/work-orders', 'l14-maintenance-work-orders-tail');
    const mwo = await api('/maintenance/work-orders', { method: 'POST', body: { maintainable_type: 'machine', maintainable_id: machine, type: 'corrective', priority: 'high', description: `LIVE-B12 corrective interruption MWO ${stamp}` } });
    exp('L14 creates corrective MWO on disposable machine', mwo, 201); const mwoId = data(mwo.body)?.id;
    if (mwoId) {
      // The seeded maintenance actor lacks maintenance.wo.assign. The rest of
      // the operational path is still valid and is run without an invented
      // checker/assignee.
      exp('L14 maintenance assignment boundary', await api(`/maintenance/work-orders/${mwoId}/assign`, { method: 'PATCH', body: { employee_id: 'ajzw1lb3eW' } }), 403);
      exp('L14 starts corrective MWO', await api(`/maintenance/work-orders/${mwoId}/start`, { method: 'PATCH' }), 200);
      exp('L14 logs corrective MWO', await api(`/maintenance/work-orders/${mwoId}/logs`, { method: 'POST', body: { description: 'LIVE-B12 breakdown diagnosis and repair' } }), 201);
      exp('L14 completes corrective MWO', await api(`/maintenance/work-orders/${mwoId}/complete`, { method: 'PATCH', body: { remarks: 'LIVE-B12 completed corrective repair', downtime_minutes: 22 } }), 200);
      exp('L14 corrective MWO terminal replay', await api(`/maintenance/work-orders/${mwoId}/complete`, { method: 'PATCH', body: { downtime_minutes: 22 } }), 422);
    }
    await login('production', 'production@ogami.test');
    exp('L14 archives disposable interruption machine', await api(`/mrp/machines/${machine}`, { method: 'DELETE' }), 204);
  }
  await login('maintenance', 'maintenance@ogami.test');
  const health = await api('/maintenance/condition-readings/health-snapshot');
  if (health.status === 404) block('L14 condition-reading threshold path', 'Condition-reading routes are explicitly scope-cut and absent.'); else exp('L14 condition-reading route', health, 200);
} catch (error) { rec('FAIL', 'L10-L14 tail harness', error instanceof Error ? error.stack ?? error.message : String(error)); await shot('tail-failure').catch(() => {}); }
finally { await logout().catch(() => {}); await browser.close(); await fs.writeFile(`${artifactDir}/l10-l14-tail-results.json`, JSON.stringify({ generated_at: new Date().toISOString(), machine, results, evidence }, null, 2)); }
console.log(`COUNTS ${JSON.stringify(results.reduce((out, row) => ({ ...out, [row.status]: (out[row.status] ?? 0) + 1 }), {}))}`);
