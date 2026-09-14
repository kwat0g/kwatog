import { firefox } from '../../spa/node_modules/playwright/index.mjs';
import fs from 'node:fs/promises';

const base = 'http://localhost';
const artifact = '/home/kwat0g/Desktop/kwatog/audit/live-2026-09-14/artifacts';
const browser = await firefox.launch({ headless: true });
const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
const page = await context.newPage();
const results = [];
const ids = { payrollPeriod: '0ldwDmpVa9', schedule: 'dGypLxpvAg', scheduleReject: 'GqkbAVwxd1', previousSo: '40awq4bKG2' };
let actor = 'signed-out';
let guard = 'none';
let lastLogin = 0;
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const data = (body) => body?.data ?? body;
const rows = (body) => Array.isArray(data(body)) ? data(body) : Array.isArray(data(body)?.data) ? data(body).data : [];
const idOf = (body) => data(body)?.id ?? data(body)?.hash_id ?? null;
const short = (value) => JSON.stringify(value ?? '').slice(0, 1400);
function record(status, name, detail = '') { results.push({ status, actor, name, detail }); console.log(`${status} ${actor} ${name}${detail ? ` :: ${detail}` : ''}`); }
function expect(name, response, accepted, detail = '') { const statuses = Array.isArray(accepted) ? accepted : [accepted]; const ok = statuses.includes(response.status); record(ok ? 'PASS' : 'FAIL', name, `HTTP ${response.status}; expected ${statuses.join('/')} ${detail}; body=${short(response.body)}`); return ok; }
function blocked(name, detail) { record('BLOCKED', name, detail); }
function observed(name, detail) { record('OBSERVED', name, detail); }
async function api(path, options = {}) {
  return page.evaluate(async ({ path, options }) => {
    const token = document.cookie.split('; ').find((cookie) => cookie.startsWith('XSRF-TOKEN='))?.slice(10);
    const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...(token ? { 'X-XSRF-TOKEN': decodeURIComponent(token) } : {}), ...(options.body === undefined ? {} : { 'Content-Type': 'application/json' }), ...(options.headers ?? {}) };
    const requestPath = path.startsWith('/api/') || path.startsWith('/sanctum/') ? path : `/api/v1${path}`;
    const response = await fetch(requestPath, { method: options.method ?? 'GET', credentials: 'include', headers, body: options.body === undefined ? undefined : JSON.stringify(options.body) });
    const text = await response.text(); let parsed; try { parsed = JSON.parse(text); } catch { parsed = text; }
    return { status: response.status, body: parsed, contentType: response.headers.get('content-type'), requestId: response.headers.get('x-request-id') };
  }, { path, options });
}
async function logout() { if (actor !== 'signed-out') { const path = guard === 'supplier' ? '/b2b/supplier/logout' : guard === 'customer' ? '/b2b/customer/logout' : '/auth/logout'; await api(path, { method: 'POST' }).catch(() => {}); } await context.clearCookies(); actor = 'signed-out'; guard = 'none'; }
async function loginInternal(alias, email) { await logout(); const wait = Math.max(0, 13000 - (Date.now() - lastLogin)); if (wait) await sleep(wait); await page.goto(`${base}/login`, { waitUntil: 'domcontentloaded' }); await page.locator('input[type="email"], input[name="email"]').first().fill(email); await page.locator('input[type="password"]').first().fill('password'); await page.getByRole('button', { name: /sign in|log in/i }).click(); await page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 25000 }); actor = alias; guard = 'internal'; lastLogin = Date.now(); expect(`${alias} authenticated`, await api('/auth/user'), 200); }
async function loginPortal(type, alias, email) { await logout(); const wait = Math.max(0, 13000 - (Date.now() - lastLogin)); if (wait) await sleep(wait); const prefix = type === 'supplier' ? '/portal/supplier' : '/portal/customer'; await page.goto(`${base}${prefix}/login`, { waitUntil: 'domcontentloaded' }); expect(`${alias} portal csrf`, await api('/sanctum/csrf-cookie'), [200, 204]); expect(`${alias} portal login`, await api(`/b2b/${type}/login`, { method: 'POST', body: { email, password: 'password' } }), 200); await page.goto(`${base}${prefix}`, { waitUntil: 'domcontentloaded' }); await page.waitForURL((url) => url.pathname === prefix || (url.pathname.startsWith(`${prefix}/`) && !url.pathname.endsWith('/login')), { timeout: 25000 }); actor = alias; guard = type; lastLogin = Date.now(); expect(`${alias} portal identity`, await api(`/b2b/${type}/me`), 200); }
async function ui(path, name) { const response = await page.goto(`${base}${path}`, { waitUntil: 'domcontentloaded' }); await page.locator('body').waitFor({ state: 'visible', timeout: 20000 }); await page.waitForFunction(() => document.body.innerText.trim().length > 20, null, { timeout: 25000 }).catch(() => {}); await page.screenshot({ path: `${artifact}/a10-a18-cont-${name}.png`, fullPage: true }).catch(() => {}); record(/application error|internal server error|something went wrong/i.test(await page.locator('body').innerText()) ? 'FAIL' : 'PASS', `${name} UI`, `document=${response?.status() ?? 'none'} uiUrl=${page.url()}`); }

try {
  // Normalize A10: HR correction is an intentional RBAC denial; admin recovery
  // returns this disposable stalled queue record to draft without touching seeds.
  await loginInternal('hr', 'hr@ogami.test');
  expect('A10 HR correction permission denial is intentional', await api(`/payroll-periods/${ids.payrollPeriod}/request-correction`, { method: 'PATCH', body: { reason: 'LIVE-B9 permission boundary' } }), 403);
  await loginInternal('admin', 'admin@ogami.test');
  expect('A10 disposable processing period force-unlock', await api(`/payroll-periods/${ids.payrollPeriod}/force-unlock`, { method: 'POST', body: { reason: 'LIVE-B9 queue unavailable after serial live run' } }), 200);
  await api(`/payroll-periods/${ids.payrollPeriod}`);
  blocked('A10 compute/anomaly/Finance downstream', 'The live compute handoff stayed processing during the serial run; no anomaly, GL, bank, payslip, or disbursement effect was claimed. The disposable period was force-unlocked to draft as recovery cleanup.');

  // A11 valid budget lifecycle. A leaf expense account is required; finance2
  // is used as the distinct same-role checker, not as a VP substitute.
  await loginInternal('finance', 'finance@ogami.test');
  await ui('/budgeting', 'a11-budget-valid');
  const fyRows = rows((await api('/budgets/fiscal-years')).body);
  const accountRows = rows((await api('/accounts?per_page=100')).body);
  const fy = fyRows[0];
  const expenseLeaf = accountRows.find((row) => /expense/i.test(String(row.type ?? '')) && row.parent_id && row.is_active !== false);
  if (!fy?.id || !expenseLeaf?.id) {
    blocked('A11 valid budget lifecycle', `No fiscal year/leaf expense fixture; fy=${short(fy)} account=${short(expenseLeaf)}`);
  } else {
    const budget = await api('/budgets', { method: 'POST', body: { fiscal_year_id: fy.id, budget_type: 'operating', name: `LIVE-B9 valid budget ${Date.now()}`, line_items: [{ account_id: expenseLeaf.id, jan: '1000.00', feb: '0.00', mar: '0.00' }] } });
    expect('A11 valid disposable budget create', budget, 201);
    ids.budget = idOf(budget.body);
    if (ids.budget) {
      await expect('A11 valid budget submit', await api(`/budgets/${ids.budget}/submit`, { method: 'POST' }), 200);
      expect('A11 Finance maker self-approval denial', await api(`/budgets/${ids.budget}/approve`, { method: 'POST' }), 422);
      await loginInternal('finance2', 'finance2@ogami.test');
      await ui(`/budgeting/${ids.budget}`, 'a11-finance2-budget-checker');
      const checked = await api(`/budgets/${ids.budget}/approve`, { method: 'POST' });
      expect('A11 distinct Finance2 budget checker approval', checked, 200);
      if (checked.status === 200) {
        observed('A11 budget approval actor/lifecycle', `submitted_by=finance; approved_by=finance2; status=${data(checked.body)?.status}; total_allocated=${data(checked.body)?.total_allocated}`);
        expect('A11 Finance2 active budget replay', await api(`/budgets/${ids.budget}/approve`, { method: 'POST' }), 422);
        expect('A11 Finance2 closes active budget', await api(`/budgets/${ids.budget}/close`, { method: 'POST' }), 200);
        expect('A11 Finance2 closed budget replay', await api(`/budgets/${ids.budget}/close`, { method: 'POST' }), 422);
        const availability = await api(`/budgets/check-availability?department_id=${expenseLeaf.parent_id}&amount=100.00&fiscal_year_id=${fy.id}`);
        expect('A11 budget availability exact decimal', availability, 200);
        await expect('A11 budget actuals sync valid fiscal year', await api('/budgets/sync-actuals', { method: 'POST', body: { fiscal_year_id: fy.id } }), 202);
      }
    }
  }

  // A14 corrected route: draft -> request customer confirmation -> portal response.
  await loginPortal('customer', 'customer-portal', 'portal@cust.test');
  const catalog = rows((await api('/b2b/customer/catalog')).body);
  if (!catalog[1]?.id) blocked('A14 corrected customer negotiation', 'No second catalog product was available for a separate disposable draft.');
  else {
    const order = await api('/b2b/customer/orders', { method: 'POST', body: { date: '2026-09-14', notes: 'LIVE-B9 corrected customer response order', items: [{ product_id: catalog[1].id, quantity: '1.000', delivery_date: '2026-11-20' }] } });
    expect('A14 corrected disposable SO create', order, 201);
    ids.customerSoCorrected = idOf(order.body);
    if (ids.customerSoCorrected) {
      await loginInternal('crm', 'crm@ogami.test');
      await ui(`/crm/sales-orders/${ids.customerSoCorrected}`, 'a14-corrected-crm-so');
      const release = await api(`/crm/sales-orders/${ids.customerSoCorrected}/request-customer-confirmation`, { method: 'POST', body: {} });
      expect('A14 CRM releases draft for customer confirmation', release, 200);
      if (release.status === 200) {
        await loginPortal('customer', 'customer-portal', 'portal@cust.test');
        const response = await api(`/b2b/customer/orders/${ids.customerSoCorrected}/respond`, { method: 'POST', body: { type: 'accept', notes: 'LIVE-B9 corrected customer acceptance' } });
        expect('A14 corrected customer SO response', response, 201);
        if (response.status === 201) {
          ids.soResponseCorrected = idOf(response.body);
          expect('A14 corrected customer response replay', await api(`/b2b/customer/orders/${ids.customerSoCorrected}/respond`, { method: 'POST', body: { type: 'accept', notes: 'exact replay' } }), [409, 422]);
          await loginInternal('crm', 'crm@ogami.test');
          const responseRows = rows((await api(`/crm/sales-orders/${ids.customerSoCorrected}/responses`)).body);
          const responseRow = responseRows.find((row) => row.id === ids.soResponseCorrected) ?? responseRows[0];
          if (responseRow?.id) expect('A14 CRM accepts corrected SO response', await api(`/crm/sales-order-responses/${responseRow.id}/accept`, { method: 'PATCH', body: {} }), 200);
          observed('A14 corrected customer SO financial/chain state', `so=${ids.customerSoCorrected}; response=${ids.soResponseCorrected}; body=${short(response.body)}`);
        }
      } else blocked('A14 corrected customer response', `Draft release returned ${release.status}; no portal response sent.`);
    }
  }
  // Cancel the first corrected-path failure only if its disposable confirmed
  // record is still cancellable; this does not touch a seeded SO.
  await loginInternal('crm', 'crm@ogami.test');
  expect('A14 cancel first disposable confirmed SO', await api(`/crm/sales-orders/${ids.previousSo}/cancel`, { method: 'POST', body: { reason: 'LIVE-B9 cleanup of incorrect first-path fixture' } }), [200, 422]);

  // A15 status is submitted, not pending, in the live internal queue.
  await loginInternal('admin', 'admin@ogami.test');
  await ui('/accounting/portal-access', 'a15-submitted-review');
  const schedules = rows((await api('/b2b/portal-access/delivery-schedules?per_page=100')).body);
  const ack = schedules.find((row) => row.id === ids.schedule && row.status === 'submitted');
  const reject = schedules.find((row) => row.id === ids.scheduleReject && row.status === 'submitted');
  if (ack?.id) {
    expect('A15 acknowledge submitted schedule', await api(`/b2b/portal-access/delivery-schedules/${ack.id}/acknowledge`, { method: 'POST', body: {} }), 200);
    expect('A15 acknowledge terminal replay', await api(`/b2b/portal-access/delivery-schedules/${ack.id}/acknowledge`, { method: 'POST', body: {} }), 422);
  } else blocked('A15 submitted schedule acknowledgement', `Target ${ids.schedule} not present in submitted state.`);
  if (reject?.id) {
    expect('A15 reject submitted schedule', await api(`/b2b/portal-access/delivery-schedules/${reject.id}/reject`, { method: 'POST', body: { reason: 'LIVE-B9 corrected schedule rejection' } }), 200);
    expect('A15 reject terminal replay', await api(`/b2b/portal-access/delivery-schedules/${reject.id}/reject`, { method: 'POST', body: { reason: 'replay' } }), 422);
  } else blocked('A15 submitted schedule rejection', `Target ${ids.scheduleReject} not present in submitted state.`);
  const notifications = await api('/notifications?per_page=100');
  observed('A15 corrected notification evidence', `rows=${rows(notifications.body).length}; schedule_ack=${ack?.id ?? null}; schedule_reject=${reject?.id ?? null}`);
} catch (error) {
  record('FAIL', 'A10-A18 continuation harness', error instanceof Error ? error.stack ?? error.message : String(error));
} finally {
  await logout().catch(() => {});
  await browser.close();
  await fs.writeFile(`${artifact}/a10-a18-continuation-results.json`, JSON.stringify({ generated_at: new Date().toISOString(), ids, results }, null, 2));
}
const counts = results.reduce((out, row) => ({ ...out, [row.status]: (out[row.status] ?? 0) + 1 }), {});
console.log(`COUNTS ${JSON.stringify(counts)}`);
console.log(`IDS ${JSON.stringify(ids)}`);
