import { firefox } from '../../spa/node_modules/playwright/index.mjs';

const browser = await firefox.launch({ headless: true });
const context = await browser.newContext();
const page = await context.newPage();
const password = 'password';
let actor = 'signed-out';
let lastLogin = 0;
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
async function api(path, options = {}) {
  return page.evaluate(async ({ path, options }) => {
    const xsrf = document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.slice(10);
    const r = await fetch(`/api/v1${path}`, { method: options.method ?? 'GET', credentials: 'include', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...(xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } : {}), ...(options.body === undefined ? {} : { 'Content-Type': 'application/json' }) }, body: options.body === undefined ? undefined : JSON.stringify(options.body) });
    return { status: r.status, text: await r.text() };
  }, { path, options });
}
async function logout() { if (actor !== 'signed-out') await api('/auth/logout', { method: 'POST' }).catch(() => {}); await context.clearCookies(); actor = 'signed-out'; }
async function login(email) { await logout(); const wait = Math.max(0, 13000 - (Date.now() - lastLogin)); if (wait) await sleep(wait); await page.goto('http://localhost/login'); await page.locator('input[type="email"],input[name="email"]').first().fill(email); await page.locator('input[type="password"]').first().fill(password); await page.getByRole('button', { name: /sign in|log in/i }).click(); await page.waitForURL((u) => !u.pathname.endsWith('/login')); actor = email; lastLogin = Date.now(); }
async function hit(path, options) { const r = await api(path, options); console.log(actor, options?.method ?? 'GET', path, r.status, r.text.slice(path.includes('purchase-orders') ? 0 : 180, path.includes('purchase-orders') ? 5000 : 360)); }
try {
  await login('purchasing@ogami.test');
  await hit('/purchasing/purchase-orders/R8epjLbOaD', { method: 'DELETE' });
  await hit('/purchasing/approved-suppliers/GkXwOM7NP5', { method: 'DELETE' });
  await login('warehouse@ogami.test');
  await hit('/purchasing/purchase-orders/DzAwdKb5ld');
  await hit('/inventory/stock-levels?per_page=2');
  await hit('/stock-levels?per_page=2');
  await hit('/inventory/stock-movements?per_page=2');
  await hit('/stock-movements?per_page=2');
  await hit('/inventory/items/zW1p05N0BA', { method: 'DELETE' });
  await hit('/inventory/warehouses/GqkbAVwxd1', { method: 'DELETE' });
  await hit('/inventory/item-categories/0ldwDmpVa9', { method: 'DELETE' });
  await hit('/inventory/uoms/BnoNyGw56v', { method: 'DELETE' });
} finally { await logout(); await browser.close(); }
