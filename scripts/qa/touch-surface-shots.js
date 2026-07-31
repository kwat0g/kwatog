/**
 * Screenshot the touch PWAs (factory floor, driver, maintenance tech) at a real
 * phone viewport, in both themes. These are the surfaces the UI-consistency pass
 * rewrote onto the shared primitives, and they are the ones no desktop
 * screenshot run ever covers.
 *
 *   node scripts/qa/touch-surface-shots.js
 */
const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright-core');

const BASE = (process.env.BASE_URL || 'http://localhost').replace(/\/$/, '');
const OUT = path.resolve(__dirname, '..', '..', 'docs', 'defense-screenshots', 'touch');

// iPhone 14-ish logical viewport — the class of device actually used on the floor.
const VIEWPORT = { width: 390, height: 844 };

const WALKS = [
  {
    who: 'production@ogami.test',
    label: 'factory',
    routes: [['/factory', 'active-orders'], ['/factory/qc', 'qc-quick-check']],
  },
  {
    who: 'driver@ogami.test',
    label: 'driver',
    routes: [['/driver', 'delivery-list']],
  },
  {
    who: 'maintenance@ogami.test',
    label: 'maintenance',
    routes: [
      ['/maintenance/mobile', 'work-orders'],
      ['/maintenance/mobile/condition-reading', 'condition-reading'],
    ],
  },
];

async function login(page, email) {
  await page.goto(`${BASE}/login`, { waitUntil: 'networkidle', timeout: 25000 });
  await page.fill('input[type="email"]', email);
  await page.fill('input[type="password"]', 'password');
  await page.click('button[type="submit"]');
  await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 15000 });
}

async function setTheme(page, theme) {
  await page.evaluate((t) => document.documentElement.setAttribute('data-theme', t), theme);
}

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ channel: 'chrome', headless: true, args: ['--no-sandbox'] });
  const results = [];

  for (const walk of WALKS) {
    const context = await browser.newContext({ viewport: VIEWPORT, deviceScaleFactor: 2 });
    const page = await context.newPage();
    const consoleErrors = [];
    page.on('console', (m) => m.type() === 'error' && consoleErrors.push(m.text().slice(0, 200)));
    page.on('pageerror', (e) => consoleErrors.push(`PAGEERROR: ${String(e).slice(0, 200)}`));

    try {
      await login(page, walk.who);
    } catch (e) {
      results.push([`${walk.label} login`, `FAIL: ${String(e.message || e).slice(0, 140)}`]);
      await context.close();
      continue;
    }

    for (const [route, name] of walk.routes) {
      for (const theme of ['light', 'dark']) {
        const before = consoleErrors.length;
        try {
          await page.goto(BASE + route, { waitUntil: 'networkidle', timeout: 25000 });
          await setTheme(page, theme);
          await page.waitForTimeout(350); // let the theme transition settle
          const body = (await page.textContent('body')) || '';
          await page.screenshot({ path: path.join(OUT, `${walk.label}-${name}-${theme}.png`) });
          const fresh = consoleErrors.slice(before);
          const problems = [];
          if (body.trim().length < 40) problems.push('blank page');
          if (/something went wrong|unexpected error/i.test(body)) problems.push('render error');
          if (fresh.length) problems.push(`${fresh.length} console error(s): ${fresh[0]}`);
          results.push([`${walk.label} ${name} ${theme}`, problems.length ? `FAIL: ${problems.join('; ')}` : 'OK']);
        } catch (e) {
          results.push([`${walk.label} ${name} ${theme}`, `FAIL: ${String(e.message || e).slice(0, 140)}`]);
        }
      }
    }
    await context.close();
  }

  await browser.close();
  const pad = Math.max(...results.map(([l]) => l.length));
  console.log('\nTouch-surface screenshot walk\n' + '─'.repeat(pad + 8));
  for (const [label, status] of results) console.log(`${label.padEnd(pad)}  ${status}`);
  console.log(`\nPNGs: ${OUT}`);
  process.exit(results.some(([, s]) => s.startsWith('FAIL')) ? 1 : 0);
})();
