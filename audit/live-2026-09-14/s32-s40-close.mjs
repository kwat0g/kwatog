import { firefox } from '../../spa/node_modules/playwright/index.mjs';

const browser = await firefox.launch({ headless: true });
const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
const page = await context.newPage();
let actor = 'signed-out';
let lastLogin = 0;
const results = [];
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
function record(status, name, detail = '') { results.push({ status, actor, name, detail }); console.log(`${status} ${name}${detail ? ` :: ${detail}` : ''}`); }
function expect(name, response, statuses) { const expected = Array.isArray(statuses) ? statuses : [statuses]; const ok = expected.includes(response.status); record(ok ? 'PASS' : 'FAIL', name, `HTTP ${response.status}; expected ${expected.join('/')} body=${JSON.stringify(response.body ?? '').slice(0, 1000)}`); }
async function api(path, options = {}) { return page.evaluate(async ({ path, options }) => { const token = document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.slice(10); const response = await fetch(`/api/v1${path}`, { method: options.method ?? 'GET', credentials: 'include', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...(token ? { 'X-XSRF-TOKEN': decodeURIComponent(token) } : {}), ...(options.body === undefined ? {} : { 'Content-Type': 'application/json' }) }, body: options.body === undefined ? undefined : JSON.stringify(options.body) }); const text = await response.text(); let body; try { body = JSON.parse(text); } catch { body = text; } return { status: response.status, body }; }, { path, options }); }
async function logout() { if (actor !== 'signed-out') await api('/auth/logout', { method: 'POST' }).catch(() => {}); await context.clearCookies(); actor = 'signed-out'; }
async function login(alias, email) { await logout(); const wait = Math.max(0, 13000 - (Date.now() - lastLogin)); if (wait) await sleep(wait); await page.goto('http://localhost/login', { waitUntil: 'domcontentloaded' }); await page.locator('input[type="email"], input[name="email"]').first().fill(email); await page.locator('input[type="password"]').first().fill('password'); await page.getByRole('button', { name: /sign in|log in/i }).click(); await page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 20000 }); actor = alias; lastLogin = Date.now(); expect(`${alias} authenticated`, await api('/auth/user'), 200); }
try {
  await login('qc', 'qc@ogami.test');
  expect('S38 NCR preventive action record', await api('/quality/ncrs/MYAbJg3wBl/actions', { method: 'POST', body: { action_type: 'preventive', description: 'Update the control plan and train the injection team to prevent recurrence.', performed_at: '2026-09-14', due_date: '2026-09-20' } }), 201);
  expect('S38 NCR close after corrective and preventive actions', await api('/quality/ncrs/MYAbJg3wBl/close', { method: 'POST' }), 200);
  expect('S38 NCR terminal replay final', await api('/quality/ncrs/MYAbJg3wBl/close', { method: 'POST' }), 422);
  await login('crm', 'crm@ogami.test');
  expect('S38 complaint resolve after final NCR close', await api('/crm/complaints/GqkbAVwxd1/resolve', { method: 'POST' }), 200);
  expect('S38 complaint close after final NCR close', await api('/crm/complaints/GqkbAVwxd1/close', { method: 'POST' }), 200);
  expect('S38 complaint terminal replay final', await api('/crm/complaints/GqkbAVwxd1/close', { method: 'POST' }), 422);
} catch (error) { record('FAIL', 'S32-S40 closure harness', error instanceof Error ? error.stack ?? error.message : String(error)); }
finally { await logout().catch(() => {}); await browser.close(); }
console.log(JSON.stringify({ counts: results.reduce((counts, row) => ({ ...counts, [row.status]: (counts[row.status] ?? 0) + 1 }), {}), results }, null, 2));
