import { firefox } from '../../spa/node_modules/playwright/index.mjs';

const browser = await firefox.launch({ headless: true });
const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
const page = await context.newPage();
try {
  await page.goto('http://localhost/login', { waitUntil: 'domcontentloaded', timeout: 30000 });
  await page.locator('input[type="email"], input[name="email"]').first().fill('purchasing@ogami.test');
  await page.locator('input[type="password"]').first().fill('password');
  await page.getByRole('button', { name: /sign in|log in/i }).click();
  await page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 30000 }).catch(() => {});
  await page.goto('http://localhost/purchasing/purchase-orders/35Mbaeb49K', { waitUntil: 'domcontentloaded', timeout: 30000 });
  await page.locator('body').waitFor({ state: 'visible', timeout: 20000 }).catch(() => {});
  await page.screenshot({ path: '/home/kwat0g/Desktop/kwatog/audit/live-2026-09-14/artifacts/l15-l17-failure-LIVE-B13-F01-supplier-response-replay.png', fullPage: true });
} finally {
  await page.evaluate(async () => fetch('/api/v1/auth/logout', { method: 'POST', credentials: 'include', headers: { Accept: 'application/json' } })).catch(() => {});
  await context.clearCookies();
  await browser.close();
}
