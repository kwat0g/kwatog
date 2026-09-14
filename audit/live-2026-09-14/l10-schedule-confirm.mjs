import { firefox } from '../../spa/node_modules/playwright/index.mjs';
const browser = await firefox.launch({ headless: true });
const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
const page = await context.newPage();
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
await page.goto('http://localhost/login', { waitUntil: 'domcontentloaded' });
await page.locator('input[type="email"], input[name="email"]').first().fill('production@ogami.test');
await page.locator('input[type="password"]').first().fill('password');
await page.getByRole('button', { name: /sign in|log in/i }).click();
await page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 30000 });
const call = async (path, options = {}) => page.evaluate(async ({ path, options }) => {
  const xsrf = document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.slice('XSRF-TOKEN='.length);
  const response = await fetch(`/api/v1${path}`, { method: options.method ?? 'GET', credentials: 'include', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...(xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } : {}) }, body: options.body ? JSON.stringify(options.body) : undefined });
  const text = await response.text(); let body; try { body = JSON.parse(text); } catch { body = text; }
  return { status: response.status, body };
}, { path, options });
const identity = await call('/auth/user');
console.log(`IDENTITY ${identity.status}`);
const result = await call('/mrp/scheduler/confirm', { method: 'POST', body: { schedule_ids: ['GkXwO7wP52'] } });
console.log(`CONFIRM ${result.status} ${JSON.stringify(result.body).slice(0, 1600)}`);
await sleep(200);
const replay = await call('/mrp/scheduler/confirm', { method: 'POST', body: { schedule_ids: ['GkXwO7wP52'] } });
console.log(`REPLAY ${replay.status} ${JSON.stringify(replay.body).slice(0, 1600)}`);
await call('/auth/logout', { method: 'POST' });
await context.clearCookies();
await browser.close();
