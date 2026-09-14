import { firefox } from '../../spa/node_modules/playwright/index.mjs';

const base = 'http://localhost';
const browser = await firefox.launch({ headless: true });
const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
const page = await context.newPage();
let actor = 'signed-out';
let lastLogin = 0;
const results = [];
const ids = { ncr: 'MYAbJg3wBl', complaint: 'GqkbAVwxd1', inspection: 'MYAbJ3wBl7', abandonedWo: 'DzAwdKb5ld', customerA: '0ldwDmpVa9', customerB: 'BnoNyGw56v' };
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const data = (body) => body?.data ?? body;
function record(status, name, detail = '') { results.push({ status, actor, name, detail }); console.log(`${status} ${name}${detail ? ` :: ${detail}` : ''}`); }
function expect(name, response, statuses, detail = '') { const expected = Array.isArray(statuses) ? statuses : [statuses]; const ok = expected.includes(response.status); record(ok ? 'PASS' : 'FAIL', name, `HTTP ${response.status}; expected ${expected.join('/')} ${detail}; body=${JSON.stringify(response.body ?? '').slice(0, 1000)}`); return ok; }
async function api(path, options = {}) {
  return page.evaluate(async ({ path, options }) => {
    const token = document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.slice(10);
    const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...(token ? { 'X-XSRF-TOKEN': decodeURIComponent(token) } : {}), ...(options.body === undefined ? {} : { 'Content-Type': 'application/json' }) };
    const response = await fetch(`/api/v1${path}`, { method: options.method ?? 'GET', credentials: 'include', headers, body: options.body === undefined ? undefined : JSON.stringify(options.body) });
    const text = await response.text(); let body; try { body = JSON.parse(text); } catch { body = text; }
    return { status: response.status, body, contentType: response.headers.get('content-type') };
  }, { path, options });
}
async function logout() { if (actor !== 'signed-out') await api('/auth/logout', { method: 'POST' }).catch(() => {}); await context.clearCookies(); actor = 'signed-out'; }
async function login(alias, email) { await logout(); const wait = Math.max(0, 13000 - (Date.now() - lastLogin)); if (wait) await sleep(wait); await page.goto(`${base}/login`, { waitUntil: 'domcontentloaded' }); await page.locator('input[type="email"], input[name="email"]').first().fill(email); await page.locator('input[type="password"]').first().fill('password'); await page.getByRole('button', { name: /sign in|log in/i }).click(); await page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 20000 }); actor = alias; lastLogin = Date.now(); expect(`${alias} authenticated`, await api('/auth/user'), 200); }

try {
  await login('production', 'production@ogami.test');
  await expect('S36 abandon failed disposable WO cleanup', await api(`/production/work-orders/${ids.abandonedWo}/cancel`, { method: 'POST', body: { reason: 'B5 failed fixture cleanup after mold availability probe' } }), 200);

  await login('qc', 'qc@ogami.test');
  const ncrOptions = await api('/quality/ncrs/options');
  expect('S38 NCR action options', ncrOptions, 200);
  const actions = data(ncrOptions.body)?.actions ?? [];
  const corrective = actions.find((action) => /correct/i.test(action.value))?.value ?? actions[0]?.value;
  await expect('S38 NCR corrective action record', await api(`/quality/ncrs/${ids.ncr}/actions`, { method: 'POST', body: { action_type: corrective, description: 'Rework one affected unit and verify against the active inspection spec.', performed_at: '2026-09-14', due_date: '2026-09-20' } }), 201);
  await expect('S38 NCR close after corrective action', await api(`/quality/ncrs/${ids.ncr}/close`, { method: 'POST' }), 200);
  await expect('S38 NCR close replay after closure', await api(`/quality/ncrs/${ids.ncr}/close`, { method: 'POST' }), 422);

  const draft = await api(`/quality/inspections/${ids.inspection}`);
  expect('S40 inspection final measurement read', draft, 200);
  const measurements = data(draft.body)?.measurements ?? [];
  if (measurements.length) {
    const rows = measurements.map((measurement) => {
      const numeric = measurement.evaluation_mode === 'numeric';
      return numeric
        ? { id: measurement.id, measured_value: String(measurement.nominal_value), notes: 'B5 final nominal actual measurement' }
        : { id: measurement.id, is_pass: true, notes: 'B5 visual pass' };
    });
    await expect('S40 visual plus numeric measurement update', await api(`/quality/inspections/${ids.inspection}/measurements`, { method: 'PATCH', body: { measurements: rows } }), 200);
    await expect('S40 inspection complete after all measurements', await api(`/quality/inspections/${ids.inspection}/complete`, { method: 'POST' }), 200);
    await expect('S40 inspection terminal replay after completion', await api(`/quality/inspections/${ids.inspection}/complete`, { method: 'POST' }), 422);
  }

  await login('crm', 'crm@ogami.test');
  await expect('S38 complaint resolve after NCR close', await api(`/crm/complaints/${ids.complaint}/resolve`, { method: 'POST' }), 200);
  await expect('S38 complaint close after NCR close', await api(`/crm/complaints/${ids.complaint}/close`, { method: 'POST' }), 200);
  await expect('S38 complaint terminal replay final', await api(`/crm/complaints/${ids.complaint}/close`, { method: 'POST' }), 422);
  await expect('S37 cleanup disposable customer A', await api(`/crm/customers/${ids.customerA}`, { method: 'DELETE' }), [204, 422]);
  await expect('S37 cleanup disposable customer B', await api(`/crm/customers/${ids.customerB}`, { method: 'DELETE' }), [204, 422]);
} catch (error) {
  record('FAIL', 'S32-S40 finalization harness', error instanceof Error ? error.stack ?? error.message : String(error));
} finally { await logout().catch(() => {}); await browser.close(); }
console.log(JSON.stringify({ ids, counts: results.reduce((counts, row) => ({ ...counts, [row.status]: (counts[row.status] ?? 0) + 1 }), {}), results }, null, 2));
