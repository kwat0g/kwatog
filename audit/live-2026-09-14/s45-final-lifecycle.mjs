import { firefox } from '../../spa/node_modules/playwright/index.mjs';

const browser = await firefox.launch({ headless: true });
const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
const page = await context.newPage();
const base = 'http://localhost';
let actor = 'signed-out'; let lastLogin = 0; const results = [];
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const data = (body) => body?.data ?? body; const list = (body) => Array.isArray(data(body)) ? data(body) : [];
const idOf = (body) => data(body)?.id ?? null;
function record(status, name, detail = '') { results.push({ status, actor, name, detail }); console.log(`${status} ${name}${detail ? ` :: ${detail}` : ''}`); }
function expect(name, response, statuses) { const expected = Array.isArray(statuses) ? statuses : [statuses]; const ok = expected.includes(response.status); record(ok ? 'PASS' : 'FAIL', name, `HTTP ${response.status}; expected ${expected.join('/')} body=${JSON.stringify(response.body ?? '').slice(0, 700)}`); }
async function api(path, options = {}) { return page.evaluate(async ({ path, options }) => { const token = document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.slice(10); const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...(token ? { 'X-XSRF-TOKEN': decodeURIComponent(token) } : {}), ...(options.body === undefined ? {} : { 'Content-Type': 'application/json' }) }; const response = await fetch(`/api/v1${path}`, { method: options.method ?? 'GET', credentials: 'include', headers, body: options.body === undefined ? undefined : JSON.stringify(options.body) }); const text = await response.text(); let body; try { body = JSON.parse(text); } catch { body = text; } return { status: response.status, body }; }, { path, options }); }
async function logout() { if (actor !== 'signed-out') await api('/auth/logout', { method: 'POST' }).catch(() => {}); await context.clearCookies(); actor = 'signed-out'; }
async function login(alias, email) { await logout(); const wait = Math.max(0, 13000 - (Date.now() - lastLogin)); if (wait) await sleep(wait); await page.goto(`${base}/login`, { waitUntil: 'domcontentloaded' }); await page.locator('input[type="email"], input[name="email"]').first().fill(email); await page.locator('input[type="password"]').first().fill('password'); await page.getByRole('button', { name: /sign in|log in/i }).click(); await page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 25000 }); actor = alias; lastLogin = Date.now(); expect(`${alias} authenticated`, await api('/auth/user'), 200); }

try {
  await login('crm', 'crm@ogami.test');
  const customers = await api('/crm/customers?per_page=100'); const products = await api('/crm/products?per_page=100'); expect('S45 CRM customer lookup lifecycle', customers, 200); expect('S45 CRM product lookup lifecycle', products, 200);
  const customer = list(customers.body)[0]; const product = list(products.body)[0];
  const create = async (note) => api('/return-management/return-requests', { method: 'POST', body: { type: 'customer_return', customer_id: customer.id, finance_only: true, finance_only_reason: note, reason_code: 'defective', reason_description: note, items: [{ product_id: product.id, quantity: '1.000', unit_price: '31.00', condition: 'defective', reason: note }] } });
  const rejected = await create('Disposable S45 correct reject lifecycle'); expect('S45 rejected RMA create correct actor', rejected, 201); const rejectedId = idOf(rejected.body);
  await expect('S45 rejected RMA submit correct actor', await api(`/return-management/return-requests/${rejectedId}/submit`, { method: 'POST' }), 200);
  await login('depthead', 'depthead@ogami.test'); expect('S45 department reject', await api(`/return-management/return-requests/${rejectedId}/reject`, { method: 'POST', body: { reason: 'S45 rejected disposable return with sufficient remarks' } }), 200);
  await expect('S45 rejected RMA terminal submit replay', await api(`/return-management/return-requests/${rejectedId}/submit`, { method: 'POST' }), 422);
  await login('crm', 'crm@ogami.test'); const cancelled = await create('Disposable S45 correct cancel lifecycle'); expect('S45 cancelled RMA create correct actor', cancelled, 201); const cancelledId = idOf(cancelled.body);
  await expect('S45 draft RMA cancel correct actor', await api(`/return-management/return-requests/${cancelledId}/cancel`, { method: 'POST', body: { reason: 'S45 cancel disposable draft' } }), 200);
  await expect('S45 cancelled RMA terminal submit replay', await api(`/return-management/return-requests/${cancelledId}/submit`, { method: 'POST' }), 422);
} catch (error) { record('FAIL', 'S45 final lifecycle harness', error instanceof Error ? error.stack ?? error.message : String(error)); }
finally { await logout().catch(() => {}); await browser.close(); }
console.log(JSON.stringify({ counts: results.reduce((out, row) => ({ ...out, [row.status]: (out[row.status] ?? 0) + 1 }), {}), results }, null, 2));
